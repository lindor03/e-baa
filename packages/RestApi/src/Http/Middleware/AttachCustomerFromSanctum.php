<?php

namespace Webkul\RestApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Contracts\Auth\Authenticatable;

class AttachCustomerFromSanctum
{
    public function handle(Request $request, Closure $next)
    {
        if ($tokenString = $request->bearerToken()) {
            try {
                if ($accessToken = PersonalAccessToken::findToken($tokenString)) {
                    $tokenable = $accessToken->tokenable;

                    if ($tokenable instanceof \Webkul\Customer\Models\Customer) {
                        // Ensure the customer guard is active *now*
                        auth()->shouldUse('customer');
                        auth('customer')->setUser($tokenable);

                        // Let Sanctum know about the current token so abilities work:
                        if (method_exists($tokenable, 'withAccessToken')) {
                            $tokenable->withAccessToken($accessToken);
                        }

                        // Ensure request()->user() resolves to this customer
                        $request->setUserResolver(function () use ($tokenable): ?Authenticatable {
                            return $tokenable;
                        });
                    }
                }
            } catch (\Throwable $e) {
                // stay guest
            }
        }

        return $next($request);
    }
}
