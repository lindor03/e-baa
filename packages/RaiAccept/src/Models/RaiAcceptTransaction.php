<?php

namespace RaiAccept\Models;

use Illuminate\Database\Eloquent\Model;

class RaiAcceptTransaction extends Model
{
    protected $table = 'raiaccept_transactions';

    protected $fillable = [
        'gateway_order_id',
        'cart_id',
        'customer_id',
        'order_id',
        'status',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
    ];
}
