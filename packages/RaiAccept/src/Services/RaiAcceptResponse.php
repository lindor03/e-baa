<?php

namespace RaiAccept\Services;

class RaiAcceptResponse
{
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
            $input['Rrn'] ?? '',
        ];

        return implode(';', $fields) . ';';
    }
}
