<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff login can have access to several locations, and an admin can take
 * one away at any time. Signs the person out of this location's portal as
 * soon as they no longer have access to it. Registered as Livewire
 * persistent middleware too, so component requests are checked as well.
 */
class EnsureStaffBelongsToStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = Store::current();
        $storeUser = Auth::guard('store')->user();

        if ($store && $storeUser && ! $storeUser->belongsToStore($store)) {
            Auth::guard('store')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException(guards: ['store'], redirectTo: route('portal.login'));
        }

        return $next($request);
    }
}
