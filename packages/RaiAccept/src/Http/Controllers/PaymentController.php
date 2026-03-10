<?php

namespace RaiAccept\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RaiAccept\Services\RaiAcceptResponse;
use RaiAccept\Services\RaiAcceptSignature;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Sales\Repositories\OrderRepository;

class PaymentController extends Controller
{
    public function __construct(
        protected OrderRepository $orderRepository,
        protected CartRepository $cartRepository
    ) {}

    /**
     * Redirect customer to RaiAccept
     */
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
        $version    = core()->getConfigData('sales.payment_methods.raiaccept.version') ?: '1';
        $locale     = core()->getConfigData('sales.payment_methods.raiaccept.locale') ?: 'sq';
        $privateKey = core()->getConfigData('sales.payment_methods.raiaccept.private_key_pem');
        $debug      = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        $orderId      = 'ORD' . $cart->id . now()->format('YmdHis');
        $purchaseTime = now()->format('ymdHis');
        $amount       = (string)((int) round($cart->grand_total * 100));

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
        Session::put('raiaccept.order_ref', $orderId);

        $data = [
            'Version'      => $version,
            'MerchantID'   => $merchantId,
            'TerminalID'   => $terminalId,
            'PurchaseTime' => $purchaseTime,
            'OrderID'      => $orderId,
            'Currency'     => $currency,
            'TotalAmount'  => $amount,
            'PurchaseDesc' => 'Order ' . $orderId,
            'locale'       => $locale,
            'Signature'    => $signature,
        ];

        if ($debug) {

            $safe = $data;
            $safe['Signature'] = '[hidden]';

            Log::info('RaiAccept redirect request', [
                'payload'          => $safe,
                'signature_string' => $signString,
                'gateway'          => $gatewayUrl,
            ]);
        }

        return view('raiaccept::redirect', [
            'gateway' => $gatewayUrl,
            'data'    => $data,
        ]);
    }

    /**
     * Bank return URL
     */
    public function return(Request $request)
    {
        $input = $request->all();

        $tranCode  = $input['TranCode'] ?? '';
        $signature = $input['Signature'] ?? '';
        $bankKey   = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');
        $debug     = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if ($debug) {
            Log::info('RaiAccept return callback', $input);
        }

        /**
         * Verify bank signature
         */
        $isVerified = true;

        if ($bankKey && $signature) {

            $responseString = RaiAcceptResponse::buildResponseString($input);

            $isVerified = RaiAcceptSignature::verify(
                $responseString,
                $signature,
                $bankKey
            );

            if ($debug) {
                Log::info('RaiAccept return verification', [
                    'verified' => $isVerified,
                    'string'   => $responseString,
                ]);
            }
        }

        if (! $isVerified) {
            return redirect()->route('shop.checkout.cart.index');
        }

        if ($tranCode !== '000') {
            return redirect()->route('shop.checkout.cart.index');
        }

        /**
         * Restore cart if session expired
         */
        $cart = Cart::getCart();

        if (! $cart) {

            $cartId = Session::get('raiaccept.cart_id');

            if ($cartId) {
                $cart = $this->cartRepository->find($cartId);
            }

            if (! $cart) {
                return redirect()->route('shop.checkout.cart.index');
            }
        }

        /**
         * Security check (bank certification requirement)
         */
        if ((int)$input['TotalAmount'] !== (int) round($cart->grand_total * 100)) {

            Log::warning('RaiAccept amount mismatch', [
                'bank' => $input['TotalAmount'],
                'cart' => round($cart->grand_total * 100),
            ]);

            return redirect()->route('shop.checkout.cart.index');
        }

        /**
         * Prevent duplicate orders
         */
        $existingOrder = $this->orderRepository->findOneByField('cart_id', $cart->id);

        if ($existingOrder) {

            session()->flash('order_id', $existingOrder->id);

            Cart::deActivateCart();

            return redirect()->route('shop.checkout.onepage.success');
        }

        /**
         * Create order
         */
        $order = $this->orderRepository->create(Cart::prepareDataForOrder());

        /**
         * Store payment metadata
         */
        try {

            $additional = $order->payment->additional ?? [];

            $additional['raiaccept'] = [
                'tran_code' => $tranCode,
                'rrn'       => $input['Rrn'] ?? null,
                'approval'  => $input['ApprovalCode'] ?? null,
                'amount'    => $input['TotalAmount'] ?? null,
                'currency'  => $input['Currency'] ?? null,
                'raw'       => $input,
            ];

            $order->payment->additional = $additional;
            $order->payment->save();

        } catch (Exception $e) {

            if ($debug) {
                Log::warning('Metadata save failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('order_id', $order->id);

        Cart::deActivateCart();

        return redirect()->route('shop.checkout.onepage.success');
    }

    /**
     * Bank server notification
     */
    public function notify(Request $request)
    {
        $input = $request->all();

        $signature = $input['Signature'] ?? '';
        $bankKey   = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');

        $responseString = RaiAcceptResponse::buildResponseString($input);

        if (! RaiAcceptSignature::verify($responseString, $signature, $bankKey)) {

            return response(
                "Response.action=reverse\nResponse.reason=invalid_signature",
                200,
                ['Content-Type' => 'text/plain']
            );
        }

        return response(
            "Response.action=approve\nResponse.reason=ok",
            200,
            ['Content-Type' => 'text/plain']
        );
    }
}
