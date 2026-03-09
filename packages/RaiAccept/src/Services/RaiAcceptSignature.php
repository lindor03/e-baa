<?php

namespace RaiAccept\Services;

use RuntimeException;

class RaiAcceptSignature
{
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

    public static function verify(string $data, string $signatureBase64, string $publicKeyPem): bool
    {
        if (empty($publicKeyPem) || empty($signatureBase64)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if (! $publicKey) {
            return false;
        }

        $result = openssl_verify(
            $data,
            base64_decode($signatureBase64),
            $publicKey,
            OPENSSL_ALGO_SHA512
        );

        openssl_free_key($publicKey);

        return $result === 1;
    }
}
