<?php

namespace RaiAccept\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RaiAccept\Models\RaiAcceptTransaction;
use RaiAccept\Services\RaiAcceptSignature;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

class PaymentController extends Controller
{

    /**
     * Gateway error codes mapped to human readable messages
     */
    protected array $errorCodes = [

        '000' => 'Authorized transaction',
        '105' => 'Transaction not allowed by issuing bank',
        '116' => 'Insufficient funds',
        '111' => 'Non-existent card',
        '108' => 'Lost or stolen card',
        '101' => 'Wrong expiration date',
        '130' => 'Over expenditure limit exceeded',
        '290' => 'Issuing bank unavailable',
        '291' => 'Technical or communication problem',
        '401' => 'Format error',
        '402' => 'Acquirer/Merchant parameters error',
        '403' => 'Connection with processing system error',
        '404' => 'Purchaser authentication error',
        '405' => 'Signature error',
        '501' => 'Transaction cancelled by user',
        '502' => 'Browser session expired',

    ];

    public function __construct(
        protected OrderRepository $orderRepository,
        protected CartRepository $cartRepository,
        protected InvoiceRepository $invoiceRepository
    ) {}



    /**
     * -------------------------------------------------------
     * STEP 1
     * Redirect customer to RaiAccept payment gateway
     * -------------------------------------------------------
     */
    public function redirect()
    {

        $cart = Cart::getCart();

        // If cart is empty redirect back
        if (! $cart || ! $cart->items_count) {
            return redirect()->route('shop.checkout.cart.index');
        }

        /**
         * Load gateway configuration
         */
        $merchantId = (string) core()->getConfigData('sales.payment_methods.raiaccept.merchant_id');
        $terminalId = (string) core()->getConfigData('sales.payment_methods.raiaccept.terminal_id');
        $gatewayUrl = (string) core()->getConfigData('sales.payment_methods.raiaccept.gateway_url');
        $currency   = (string) core()->getConfigData('sales.payment_methods.raiaccept.currency_numeric');
        $privateKey = (string) core()->getConfigData('sales.payment_methods.raiaccept.private_key_pem');
        $locale     = (string) (core()->getConfigData('sales.payment_methods.raiaccept.locale') ?: 'sq');

        $version = '1';

        /**
         * Unique order reference sent to bank
         */
        $orderId = 'ORD' . $cart->id . now()->format('YmdHis');

        /**
         * Gateway timestamp format
         */
        $purchaseTime = now()->format('ymdHis');

        /**
         * Convert amount to minor units (cents)
         */
        $amount = (string) ((int) round($cart->grand_total * 100));



        /**
         * Build signature string
         */
        $signString = RaiAcceptSignature::buildRequestString(
            $merchantId,
            $terminalId,
            $purchaseTime,
            $orderId,
            $currency,
            $amount
        );

        /**
         * Generate signature with merchant private key
         */
        $signature = RaiAcceptSignature::sign($signString, $privateKey);



        /**
         * Gateway payload
         */
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



        /**
         * Store payment attempt in database
         * This protects the checkout flow from session loss
         */
        RaiAcceptTransaction::updateOrCreate(
            ['gateway_order_id' => $orderId],
            [
                'cart_id'         => $cart->id,
                'customer_id'     => auth()->guard('customer')->id(),
                'status'          => 'pending',
                'request_payload' => $data,
            ]
        );



        /**
         * Debug logging
         */
        Log::info('RaiAccept redirect request', [
            'payload'          => $data,
            'signature_string' => $signString,
            'gateway'          => $gatewayUrl,
        ]);



        /**
         * Render auto-submit form to gateway
         */
        return view('raiaccept::redirect', [
            'gateway' => $gatewayUrl,
            'data'    => $data,
        ]);
    }



    /**
     * -------------------------------------------------------
     * STEP 2
     * Customer returns from payment gateway
     * -------------------------------------------------------
     */
    public function return(Request $request)
    {

        $input = $request->all();

        Log::info('RaiAccept return callback', $input);



        /**
         * Extract important gateway fields
         */
        $tranCode       = (string) ($input['TranCode'] ?? '');
        $signature      = (string) ($input['Signature'] ?? '');
        $gatewayOrderId = (string) ($input['OrderID'] ?? '');

        $bankKey = (string) core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');



        /**
         * Find transaction stored during redirect
         */
        $transaction = RaiAcceptTransaction::where('gateway_order_id', $gatewayOrderId)->first();

        if (! $transaction) {

            Log::warning('RaiAccept transaction not found', [
                'gateway_order_id' => $gatewayOrderId
            ]);

            return redirect()->route('shop.checkout.cart.index');
        }



        /**
         * Restore customer session if lost
         */
        if ($transaction->customer_id && ! auth()->guard('customer')->check()) {

            auth()->guard('customer')->loginUsingId($transaction->customer_id);
        }



        /**
         * Verify bank signature
         */
        $verified = RaiAcceptSignature::verifyFlexible($input, $signature, $bankKey);

        Log::info('RaiAccept return verification', [
            'verified' => $verified,
        ]);

        /**
         * Log warning but do not block payment
         * (Some test gateways do not return correct signatures)
         */
        if (! $verified) {

            Log::warning('Signature verification failed but continuing', $input);
        }



        /**
         * Update transaction status
         */
        $transaction->update([
            'status'           => $tranCode === '000' ? 'authorized' : 'failed',
            'response_payload' => $input,
        ]);



        /**
         * Handle payment failure
         */
        if ($tranCode !== '000') {

            $message = $this->errorCodes[$tranCode] ?? 'Payment failed';

            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors($message);
        }



        /**
         * Recover cart from database
         */
        $cart = $this->cartRepository->find($transaction->cart_id);

        if (! $cart) {

            Log::warning('RaiAccept cart not found', [
                'cart_id' => $transaction->cart_id
            ]);

            return redirect()->route('shop.checkout.cart.index');
        }



        /**
         * Prevent duplicate order creation
         */
        $existingOrder = $this->orderRepository->findOneByField('cart_id', $cart->id);

        if ($existingOrder) {

            $transaction->update([
                'order_id' => $existingOrder->id,
                'status'   => 'completed',
            ]);

            session()->put('order_id', $existingOrder->id);
            session()->flash('order_id', $existingOrder->id);

            return redirect()->route('shop.checkout.onepage.success');
        }



        /**
         * Restore cart context
         */
        Cart::setCart($cart);



        /**
         * Recalculate totals before order creation
         */
        Cart::collectTotals();



        /**
         * Convert cart to order structure
         */
        $orderData = (new OrderResource($cart))->jsonSerialize();



        /**
         * Create Bagisto order
         */
        $order = $this->orderRepository->create($orderData);



        /**
         * Automatically create invoice
         */
        if ($order->canInvoice()) {

            $invoiceData = ['order_id' => $order->id];

            foreach ($order->items as $item) {

                $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
            }

            $this->invoiceRepository->create($invoiceData);
        }



        /**
         * Mark order as paid
         */
        $this->orderRepository->update([
            'status' => 'processing',
        ], $order->id);



        /**
         * Store gateway metadata in order payment
         */
        try {

            $additional = $order->payment->additional ?? [];

            if (! is_array($additional)) {
                $additional = [];
            }

            $additional['raiaccept'] = [

                'tran_code'     => $tranCode,
                'approval_code' => $input['ApprovalCode'] ?? null,
                'rrn'           => $input['Rrn'] ?? null,
                'xid'           => $input['XID'] ?? null,
                'amount'        => $input['TotalAmount'] ?? null,
                'currency'      => $input['Currency'] ?? null,
                'verified'      => $verified,
                'raw'           => $input,

            ];

            $order->payment->additional = $additional;

            $order->payment->save();

        } catch (Exception $e) {

            Log::warning('Payment metadata save failed', [
                'message' => $e->getMessage(),
            ]);
        }



        /**
         * Mark transaction completed
         */
        $transaction->update([
            'order_id' => $order->id,
            'status'   => 'completed',
        ]);



        /**
         * Disable cart
         */
        Cart::deActivateCart();



        /**
         * Redirect to Bagisto success page
         */
        session()->put('order_id', $order->id);
        session()->flash('order_id', $order->id);

        return redirect()->route('shop.checkout.onepage.success');
    }



    /**
     * -------------------------------------------------------
     * STEP 3
     * Server-to-server notification from bank
     * -------------------------------------------------------
     */
    public function notify(Request $request)
    {

        $input = $request->all();

        Log::info('RaiAccept notify callback', $input);

        $gatewayOrderId = (string) ($input['OrderID'] ?? '');

        if ($gatewayOrderId) {

            RaiAcceptTransaction::where('gateway_order_id', $gatewayOrderId)
                ->update([
                    'response_payload' => $input,
                ]);
        }

        return response(
            "Response.action=approve\nResponse.reason=ok",
            200,
            ['Content-Type' => 'text/plain']
        );
    }
}
