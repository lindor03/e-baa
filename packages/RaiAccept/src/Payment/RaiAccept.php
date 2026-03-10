<?php

namespace RaiAccept\Payment;

use Illuminate\Support\Facades\Storage;
use Webkul\Payment\Payment\Payment;

class RaiAccept extends Payment
{
    protected $code = 'raiaccept';

    public function getRedirectUrl()
    {
        return route('raiaccept.redirect');
    }

    public function getImage()
    {
        $url = $this->getConfigData('image');

        return $url ? Storage::url($url) : null;
    }
}
