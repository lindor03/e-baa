<?php

namespace RaiAccept\Payment;

use Illuminate\Support\Facades\Storage;
use Webkul\Payment\Payment\Payment;

class RaiAccept extends Payment
{
    /**
     * Payment method code.
     *
     * @var string
     */
    protected $code = 'raiaccept';

    /**
     * Redirect URL for Bagisto checkout flow.
     */
    public function getRedirectUrl()
    {
        return route('raiaccept.redirect');
    }

    /**
     * Payment logo.
     */
    public function getImage()
    {
        $url = $this->getConfigData('image');

        return $url ? Storage::url($url) : null;
    }
}
