<?php

namespace RaiAccept\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RaiAccept\Services\RaiAcceptSignature;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Sales\Repositories\OrderRepository;

class PaymentController extends Controller
{
    protected array $errorCodes = [

        '000' => 'Authorized transaction',
        '105' => 'Transaction not allowed by issuing bank',
        '116' => 'Shortage of funds',
        '111' => 'Non-existent card',
        '108' => 'Lost or stolen card',
        '101' => 'Wrong expiration date',
        '130' => 'Allowed overexpenditure limit exceeded',
        '290' => 'Issuing bank unavailable',
        '291' => 'Technical or communication problem',
        '401' => 'Format error',
        '402' => 'Acquirer/Merchant parameters error',
        '403' => 'Connection with payment system error',
        '404' => 'Purchaser authentication error',
        '405' => 'Signature error',
        '501' => 'Transaction cancelled by user',
        '502' => 'Browser session expired',

    ];

    public function __construct(
        protected OrderRepository $orderRepository,
        protected CartRepository $cartRepository
    ) {}

    /**
     * Redirect to bank
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
                'payload' => $safe,
                'signature_string' => $signString,
                'gateway' => $gatewayUrl,
            ]);
        }

        return view('raiaccept::redirect', [
            'gateway' => $gatewayUrl,
            'data'    => $data,
        ]);
    }

    /**
     * Return from bank
     */
    public function return(Request $request)
    {
        $input = $request->all();

        $tranCode  = $input['TranCode'] ?? '';
        $signature = $input['Signature'] ?? '';
        $bankKey   = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');
        $debug     = core()->getConfigData('sales.payment_methods.raiaccept.debug');

        $errorDescription = $this->errorCodes[$tranCode] ?? 'Unknown error';

        if ($debug) {

            Log::info('RaiAccept return callback', $input);
        }

        $verified = RaiAcceptSignature::verifyFlexible(
            $input,
            $signature,
            $bankKey
        );

        if ($debug) {

            Log::info('RaiAccept return verification', [
                'verified' => $verified
            ]);
        }

        /**
         * If payment failed
         */
        if ($tranCode !== '000') {

            Log::warning('RaiAccept payment failed', [
                'code' => $tranCode,
                'description' => $errorDescription
            ]);

            session()->flash('error', 'Payment failed: '.$errorDescription);

            return redirect()->route('shop.checkout.cart.index');
        }

        /**
         * Recover cart
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
         * Save metadata
         */
        $order->payment->additional = [

            'raiaccept' => [

                'tran_code' => $tranCode,
                'description' => $errorDescription,
                'gateway_order' => $input['OrderID'] ?? null,
                'amount' => $input['TotalAmount'] ?? null,
                'currency' => $input['Currency'] ?? null,
                'rrn' => $input['Rrn'] ?? null,
                'approval_code' => $input['ApprovalCode'] ?? null,
                'raw' => $input

            ]

        ];

        $order->payment->save();

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

        $verified = RaiAcceptSignature::verifyFlexible(
            $input,
            $signature,
            $bankKey
        );

        if (! $verified) {

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
