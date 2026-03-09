<?php

namespace RaiAccept\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Repositories\OrderRepository;
use RaiAccept\Services\RaiAcceptResponse;
use RaiAccept\Services\RaiAcceptSignature;

class PaymentController extends Controller
{
    public function __construct(
        protected OrderRepository $orderRepository
    ) {}

    public function redirect()
    {
        $cart = Cart::getCart();

        if (! $cart || ! $cart->items_count) {
            return redirect()->route('shop.checkout.cart.index');
        }

        $merchantId = core()->getConfigData('sales.payment_methods.raiaccept.merchant_id');
        $terminalId = core()->getConfigData('sales.payment_methods.raiaccept.terminal_id');
        $gatewayUrl = core()->getConfigData('sales.payment_methods.raiaccept.gateway_url');
        $currency   = core()->getConfigData('sales.payment_methods.raiaccept.currency_numeric');
        $privateKey = core()->getConfigData('sales.payment_methods.raiaccept.private_key_pem');
        $debug      = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        $orderId = (string) $cart->id;
        $purchaseTime = now()->format('ymdHis');
        $amount = (int) round($cart->grand_total * 100);

        $signString = RaiAcceptSignature::buildRequestString(
            $merchantId,
            $terminalId,
            $purchaseTime,
            $orderId,
            $currency,
            $amount
        );

        $signature = RaiAcceptSignature::sign($signString, $privateKey);

        Session::put('raiaccept.cart_id', $cart->id);

        $data = [
            'Version'      => '1.0',
            'MerchantID'   => $merchantId,
            'TerminalID'   => $terminalId,
            'PurchaseTime' => $purchaseTime,
            'OrderID'      => $orderId,
            'Currency'     => $currency,
            'TotalAmount'  => $amount,
            'PurchaseDesc' => 'Order '.$orderId,
            // 'locale'       => 'al',
            'Signature'    => $signature
        ];

        if ($debug) {
            Log::info('RaiAccept redirect request', [
                'payload' => $data,
                'signature_string' => $signString
            ]);
        }

        return view('raiaccept::redirect', [
            'gateway' => $gatewayUrl,
            'data' => $data
        ]);
    }


    public function return(Request $request)
    {
        $input = $request->all();

        $tranCode = $input['TranCode'] ?? '';
        $signature = $input['Signature'] ?? '';

        $bankKey = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');
        $debug   = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if ($debug) {

            $logInput = $input;
            unset($logInput['Signature']);

            Log::info('RaiAccept return', $logInput);
        }

        $isVerified = true;

        if ($bankKey && $signature) {

            $string = RaiAcceptResponse::buildResponseString($input);

            $isVerified = RaiAcceptSignature::verify($string, $signature, $bankKey);
        }

        if (!$isVerified) {

            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('Payment verification failed');
        }

        if ($tranCode !== '000') {

            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('Payment failed');
        }

        $cart = Cart::getCart();

        if (!$cart) {

            $cartId = Session::get('raiaccept.cart_id');

            if (!$cartId) {
                return redirect()->route('shop.checkout.cart.index');
            }
        }

        $existingOrder = $this->orderRepository->findOneByField('cart_id', $cart->id);

        if (!$existingOrder) {

            $orderData = Cart::prepareDataForOrder();

            $order = $this->orderRepository->create($orderData);

        } else {

            $order = $existingOrder;
        }

        session()->flash('order_id', $order->id);

        Cart::deActivateCart();

        return redirect()->route('shop.checkout.success');
    }


    public function notify(Request $request)
    {
        $input = $request->all();

        $signature = $input['Signature'] ?? '';

        $bankKey = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');

        $debug = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if ($debug) {
            Log::info('RaiAccept notify', $input);
        }

        $isVerified = true;

        if ($bankKey && $signature) {

            $string = RaiAcceptResponse::buildResponseString($input);

            $isVerified = RaiAcceptSignature::verify($string, $signature, $bankKey);
        }

        if (!$isVerified) {

            return response(
                "Response.action=reverse\nResponse.reason=invalid_signature",
                200,
                ['Content-Type'=>'text/plain']
            );
        }

        return response(
            "Response.action=approve\nResponse.reason=ok",
            200,
            ['Content-Type'=>'text/plain']
        );
    }
}
