<?php

namespace Webkul\CustomPromotions\Http\Controllers\Shop;

use Illuminate\View\View;
use Webkul\Shop\Http\Controllers\Controller;

class CustomPromotionsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('custompromotions::shop.index');
    }
}
