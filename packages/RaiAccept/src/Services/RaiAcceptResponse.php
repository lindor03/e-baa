<?php

namespace RaiAccept\Services;

class RaiAcceptResponse
{
    /**
     * Best-effort response-string builder for gateway callback verification.
     * Adjust field order later if your bank provides the exact callback signature format.
     */
    public static function buildResponseString(array $input): string
    {
        $fields = [
            $input['MerchantID'] ?? '',
            $input['TerminalID'] ?? '',
            $input['PurchaseTime'] ?? '',
            $input['OrderID'] ?? '',
            $input['Currency'] ?? '',
            $input['TotalAmount'] ?? '',
            $input['TranCode'] ?? '',
            $input['ApprovalCode'] ?? '',
            $input['XID'] ?? '',
        ];

        return implode(';', $fields) . ';';
    }
}
