<?php

namespace Webkul\CustomSettings\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Webkul\CustomSettings\Models\CustomColor;

class ColorApiController extends Controller
{
    public function index()
    {
        $colors = CustomColor::all()->pluck('value', 'key');

        return response()->json([
            'status' => true,
            'data'   => $colors,
        ]);
    }
}
