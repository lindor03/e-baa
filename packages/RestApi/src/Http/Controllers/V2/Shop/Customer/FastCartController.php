<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Customer;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Webkul\Checkout\Facades\Cart;
use Webkul\RestApi\Http\Resources\V2\Shop\Checkout\CartResource;

class FastCartController extends Controller
{
    public function show(Request $request)
    {
        $cart = Cart::getCart();

        if (! $cart) {
            return response()->json(null);
        }

        return Cache::remember(
            $this->cacheKey($cart->id),
            now()->addSeconds(3),
            fn () => (new CartResource($cart))
                ->response()
                ->getData(true)
        );
    }

    protected function cacheKey(int $cartId): string
    {
        return "restapi:v2:cart:snapshot:{$cartId}";
    }
}
