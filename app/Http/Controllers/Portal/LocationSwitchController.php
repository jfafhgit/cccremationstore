<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Moves a staff member with access to several locations from one location's
 * portal to another's. Each location is its own subdomain with its own
 * session, so this hands over a single-use token that expires in a minute
 * rather than asking them to sign in again.
 */
class LocationSwitchController extends Controller
{
    public const HANDOFF_LIFETIME_SECONDS = 60;

    public function switch(Request $request): RedirectResponse
    {
        /** @var StoreUser $storeUser */
        $storeUser = $request->user('store');

        $location = $storeUser->stores()
            ->active()
            ->where('slug', $request->route('location'))
            ->firstOrFail();

        $token = Str::random(64);

        Cache::put(self::cacheKey($token), [
            'store_user_id' => $storeUser->id,
            'store_id' => $location->id,
        ], self::HANDOFF_LIFETIME_SECONDS);

        return redirect()->away(route('portal.handoff', ['store' => $location->slug, 'token' => $token]));
    }

    /**
     * Sign in on the destination location. Pulling the token from the cache
     * is what makes it single-use.
     */
    public function handoff(Request $request): RedirectResponse
    {
        $handoff = Cache::pull(self::cacheKey((string) $request->route('token')));
        $store = Store::current();

        $storeUser = is_array($handoff) && $handoff['store_id'] === $store->id
            ? StoreUser::whereKey($handoff['store_user_id'])->first()
            : null;

        if (! $storeUser?->belongsToStore($store)) {
            return redirect()->route('portal.login');
        }

        Auth::guard('store')->login($storeUser);
        $request->session()->regenerate();

        return redirect()->route('portal.orders');
    }

    private static function cacheKey(string $token): string
    {
        return 'portal-handoff:'.hash('sha256', $token);
    }
}
