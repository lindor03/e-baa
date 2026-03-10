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
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Transformers\OrderResource;

class PaymentController extends Controller
{

    /**
     * Gateway error codes mapped to readable messages
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
     * STEP 1
     * Redirect customer to RaiAccept payment gateway
     */
    public function redirect()
    {

        $cart = Cart::getCart();

        // Ensure cart exists and has items
        if (! $cart || ! $cart->items_count) {
            return redirect()->route('shop.checkout.cart.index');
        }

        // Load payment configuration
        $merchantId = core()->getConfigData('sales.payment_methods.raiaccept.merchant_id');
        $terminalId = core()->getConfigData('sales.payment_methods.raiaccept.terminal_id');
        $gatewayUrl = core()->getConfigData('sales.payment_methods.raiaccept.gateway_url');
        $currency   = core()->getConfigData('sales.payment_methods.raiaccept.currency_numeric');
        $privateKey = core()->getConfigData('sales.payment_methods.raiaccept.private_key_pem');

        $locale  = core()->getConfigData('sales.payment_methods.raiaccept.locale') ?: 'sq';
        $version = '1';

        // Generate unique order reference
        $orderId = 'ORD'.$cart->id.now()->format('YmdHis');

        // Payment timestamp required by gateway
        $purchaseTime = now()->format('ymdHis');

        // Amount must be in smallest unit (e.g. cents)
        $amount = (string)((int) round($cart->grand_total * 100));


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
         * Sign request using merchant private key
         */
        $signature = RaiAcceptSignature::sign($signString, $privateKey);


        /**
         * Save cart reference in session
         * used later if session is lost after bank redirect
         */
        Session::put('raiaccept.cart_id', $cart->id);
        Session::put('raiaccept.order_ref', $orderId);


        /**
         * Payload sent to bank
         */
        $data = [

            'Version'      => $version,
            'MerchantID'   => $merchantId,
            'TerminalID'   => $terminalId,
            'PurchaseTime' => $purchaseTime,
            'OrderID'      => $orderId,
            'Currency'     => $currency,
            'TotalAmount'  => $amount,
            'PurchaseDesc' => 'Order '.$orderId,
            'locale'       => $locale,
            'Signature'    => $signature

        ];


        /**
         * Debug logging
         */
        Log::info('RaiAccept redirect request', [
            'payload'          => $data,
            'signature_string' => $signString,
            'gateway'          => $gatewayUrl
        ]);


        /**
         * Render redirect form
         */
        return view('raiaccept::redirect', [
            'gateway' => $gatewayUrl,
            'data'    => $data
        ]);
    }



    /**
     * STEP 2
     * Customer returns from payment gateway
     */
    public function return(Request $request)
    {

        $input = $request->all();

        Log::info('RaiAccept return callback', $input);


        $tranCode  = $input['TranCode'] ?? '';
        $signature = $input['Signature'] ?? '';
        $bankKey   = core()->getConfigData('sales.payment_methods.raiaccept.bank_public_key_pem');


        /**
         * Verify bank signature
         * (does not block order creation)
         */
        $isVerified = RaiAcceptSignature::verifyFlexible($input, $signature, $bankKey);

        Log::info('RaiAccept return verification', [
            'verified' => $isVerified
        ]);

        if (! $isVerified) {

            Log::warning(
                'RaiAccept signature verification failed but continuing',
                $input
            );
        }



        /**
         * Handle payment errors
         */
        if ($tranCode !== '000') {

            $message = $this->errorCodes[$tranCode] ?? 'Payment failed';

            return redirect()
                ->route('shop.checkout.cart.index')
                ->withErrors($message);
        }



        /**
         * Recover cart if session lost
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
         * Create order using Bagisto standard flow
         */
        Cart::collectTotals();

        $data = (new OrderResource($cart))->jsonSerialize();

        $order = $this->orderRepository->create($data);



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
            'status' => 'processing'
        ], $order->id);



        /**
         * Save payment metadata
         */
        try {

            $additional = $order->payment->additional ?? [];

            $additional['raiaccept'] = [

                'tran_code'     => $tranCode,
                'approval_code' => $input['ApprovalCode'] ?? null,
                'rrn'           => $input['Rrn'] ?? null,
                'xid'           => $input['XID'] ?? null,
                'amount'        => $input['TotalAmount'] ?? null,
                'currency'      => $input['Currency'] ?? null,
                'raw'           => $input

            ];

            $order->payment->additional = $additional;

            $order->payment->save();

        } catch (Exception $e) {

            Log::warning('RaiAccept metadata save failed', [
                'message' => $e->getMessage()
            ]);
        }



        /**
         * Flash order id for success page
         */
        session()->flash('order_id', $order->id);


        /**
         * Disable cart after successful order
         */
        Cart::deActivateCart();


        /**
         * Redirect to success page
         */
        return redirect()->route('shop.checkout.onepage.success');
    }



    /**
     * STEP 3
     * Bank server notification (optional)
     */
    public function notify(Request $request)
    {

        $input = $request->all();

        Log::info('RaiAccept notify callback', $input);

        return response(
            "Response.action=approve\nResponse.reason=ok",
            200,
            ['Content-Type' => 'text/plain']
        );
    }

}
