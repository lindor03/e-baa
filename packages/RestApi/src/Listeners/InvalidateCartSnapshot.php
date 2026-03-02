<?php

namespace Webkul\RestApi\Listeners;

use Illuminate\Support\Facades\Cache;

class InvalidateCartSnapshot
{
    public function handle($event)
    {
        if (! isset($event->cart)) {
            return;
        }

        Cache::forget("restapi:v2:cart:snapshot:{$event->cart->id}");
    }
}
