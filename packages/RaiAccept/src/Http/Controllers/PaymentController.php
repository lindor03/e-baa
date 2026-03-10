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
use Webkul\Sales\Repositories\OrderRepository;

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

        $merchantId = (string) core()->getConfigData('sales.payment_methods.raiaccept.merchant_id');
        $terminalId = (string) core()->getConfigData('sales.payment_methods.raiaccept.terminal_id');
        $gatewayUrl = (string) core()->getConfigData('sales.payment_methods.raiaccept.gateway_url');
        $currency   = (string) core()->getConfigData('sales.payment_methods.raiaccept.currency_numeric');
        $version    = (string) (core()->getConfigData('sales.payment_methods.raiaccept.version') ?: '1.0');
        $locale     = (string) (core()->getConfigData('sales.payment_methods.raiaccept.locale') ?: 'sq');
        $privateKey = (string) core()->getConfigData('sales.payment_methods.raiaccept.private_key_pem');
        $debug      = (bool) core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if (! $merchantId || ! $terminalId || ! $gatewayUrl || ! $currency || ! $privateKey) {
            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('RaiAccept configuration is incomplete.');
        }

        $orderId      = 'ORD' . $cart->id . now()->format('YmdHis');
        $purchaseTime = now()->format('ymdHis');
        $amount       = (string) ((int) round((float) $cart->grand_total * 100));

        $signString = RaiAcceptSignature::buildRequestString(
            $merchantId,
            $terminalId,
            $purchaseTime,
            $orderId,
            $currency,
            $amount
        );

        try {
            $signature = RaiAcceptSignature::sign($signString, $privateKey);
        } catch (Exception $e) {
            if ($debug) {
                Log::error('RaiAccept signing failed', [
                    'message' => $e->getMessage(),
                ]);
            }

            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('RaiAccept signing failed.');
        }

        Session::put('raiaccept.cart_id', $cart->id);
        Session::put('raiaccept.order_ref', $orderId);
        Session::put('raiaccept.purchase_time', $purchaseTime);

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

    public function return(Request $request)
    {
        $input = $request->all();

        $tranCode = (string) ($input['TranCode'] ?? '');
        $signature = (string) ($input['Signature'] ?? '');
        $bankKey = (string) core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');
        $debug = (bool) core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if ($debug) {
            $safe = $input;
            unset($safe['Signature']);

            Log::info('RaiAccept return callback', $safe);
        }

        $isVerified = true;

        if ($bankKey && $signature) {
            $responseString = RaiAcceptResponse::buildResponseString($input);
            $isVerified = RaiAcceptSignature::verify($responseString, $signature, $bankKey);

            if ($debug) {
                Log::info('RaiAccept return verification', [
                    'verified'        => $isVerified,
                    'response_string' => $responseString,
                ]);
            }
        }

        if (! $isVerified) {
            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('Payment verification failed.');
        }

        if ($tranCode !== '000') {
            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('Payment failed or cancelled.');
        }

        $cart = Cart::getCart();

        if (! $cart) {
            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors('Cart not found after payment.');
        }

        $existingOrder = $this->orderRepository->findOneByField('cart_id', $cart->id);

        if (! $existingOrder) {
            $order = $this->orderRepository->create(Cart::prepareDataForOrder());
        } else {
            $order = $existingOrder;
        }

        try {
            $additional = $order->payment->additional ?? [];

            if (! is_array($additional)) {
                $additional = [];
            }

            $additional['raiaccept'] = [
                'tran_code'     => $tranCode,
                'gateway_order' => $input['OrderID'] ?? null,
                'amount'        => $input['TotalAmount'] ?? null,
                'currency'      => $input['Currency'] ?? null,
                'purchase_time' => $input['PurchaseTime'] ?? null,
                'raw'           => $input,
            ];

            $order->payment->additional = $additional;
            $order->payment->save();
        } catch (Exception $e) {
            if ($debug) {
                Log::warning('RaiAccept metadata save failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('order_id', $order->id);

        Cart::deActivateCart();

        return redirect()->route('shop.checkout.success');
    }

    public function notify(Request $request)
    {
        $input = $request->all();

        $signature = (string) ($input['Signature'] ?? '');
        $bankKey = (string) core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');
        $debug = (bool) core()->getConfigData('sales.payment_methods.raiaccept.debug');

        if ($debug) {
            $safe = $input;
            unset($safe['Signature']);

            Log::info('RaiAccept notify callback', $safe);
        }

        $isVerified = true;

        if ($bankKey && $signature) {
            $responseString = RaiAcceptResponse::buildResponseString($input);
            $isVerified = RaiAcceptSignature::verify($responseString, $signature, $bankKey);

            if ($debug) {
                Log::info('RaiAccept notify verification', [
                    'verified'        => $isVerified,
                    'response_string' => $responseString,
                ]);
            }
        }

        if (! $isVerified) {
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
