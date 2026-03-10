<?php

namespace RaiAccept\Services;

use RuntimeException;

class RaiAcceptSignature
{
    /**
     * Build request signature string
     */
    public static function buildRequestString(
        string $merchantId,
        string $terminalId,
        string $purchaseTime,
        string $orderId,
        string $currency,
        string $totalAmount
    ): string {

        return implode(';', [
            $merchantId,
            $terminalId,
            $purchaseTime,
            $orderId,
            $currency,
            $totalAmount,
        ]) . ';';
    }

    /**
     * Sign request with merchant private key
     */
    public static function sign(string $data, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if (! $privateKey) {
            throw new RuntimeException('Invalid merchant private key.');
        }

        $signature = '';

        $success = openssl_sign(
            $data,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA512
        );

        openssl_free_key($privateKey);

        if (! $success) {
            throw new RuntimeException('Unable to generate signature.');
        }

        return base64_encode($signature);
    }

    /**
     * Flexible response verification
     * Supports different UPC/Raiffeisen signature formats
     */
    public static function verifyFlexible(array $input, string $signatureBase64, string $publicKeyPem): bool
    {
        if (empty($signatureBase64) || empty($publicKeyPem)) {
            return false;
        }

        $publicKeyPem = trim($publicKeyPem);

        // normalize key
        if (! str_contains($publicKeyPem, 'BEGIN')) {
            $publicKeyPem =
                "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split($publicKeyPem, 64, "\n") .
                "-----END PUBLIC KEY-----";
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if (! $publicKey) {
            return false;
        }

        $variants = [

            // Variant 1 (most common UPC)
            implode(';', [
                $input['MerchantID'] ?? '',
                $input['TerminalID'] ?? '',
                $input['PurchaseTime'] ?? '',
                $input['OrderID'] ?? '',
                $input['Currency'] ?? '',
                $input['TotalAmount'] ?? '',
                $input['TranCode'] ?? '',
                $input['ApprovalCode'] ?? '',
                $input['Rrn'] ?? '',
            ]) . ';',

            // Variant 2 (some banks omit PurchaseTime)
            implode(';', [
                $input['MerchantID'] ?? '',
                $input['TerminalID'] ?? '',
                $input['OrderID'] ?? '',
                $input['Currency'] ?? '',
                $input['TotalAmount'] ?? '',
                $input['TranCode'] ?? '',
                $input['ApprovalCode'] ?? '',
                $input['Rrn'] ?? '',
            ]) . ';',

            // Variant 3 (legacy UPC)
            implode(';', [
                $input['MerchantID'] ?? '',
                $input['TerminalID'] ?? '',
                $input['PurchaseTime'] ?? '',
                $input['OrderID'] ?? '',
                $input['Currency'] ?? '',
                $input['TotalAmount'] ?? '',
                $input['TranCode'] ?? '',
                $input['ApprovalCode'] ?? '',
                $input['XID'] ?? '',
            ]) . ';',
        ];

        foreach ($variants as $string) {

            $result = openssl_verify(
                $string,
                base64_decode($signatureBase64),
                $publicKey,
                OPENSSL_ALGO_SHA512
            );

            if ($result === 1) {
                openssl_free_key($publicKey);
                return true;
            }
        }

        openssl_free_key($publicKey);

        return false;
    }
}
