<?php

namespace Webkul\Widgets\Http\Controllers\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Widgets\Helpers\WidgetResolver;

class WidgetsController extends Controller
{
    public function index(): View
    {
        return view('widgets::shop.index');
    }

    public function show(string $slug): JsonResponse
    {
        $resolver = app(WidgetResolver::class);
        $data = $resolver->get($slug);

        if (!$data) {
            return response()->json([
                'status_code' => 404, 'status' => 'error', 'messages' => 'Widget not found', 'data' => null
            ], 200);
        }

        return response()->json([
            'status_code' => 200, 'status' => 'success', 'messages' => 'OK', 'data' => $data
        ], 200);
    }
}
