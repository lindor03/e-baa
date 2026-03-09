<?php

namespace RaiAccept\Http\Controllers\Shop;

use Illuminate\View\View;
use Webkul\Shop\Http\Controllers\Controller;

class RaiAcceptController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('raiaccept::shop.index');
    }
}
