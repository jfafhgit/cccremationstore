<?php

namespace App\Http\Middleware;

use App\Enums\StoreStatus;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant into a Store model and binds it into the
 * container, and 404s for a subdomain that doesn't exist or isn't active.
 *
 * Normally the slug comes from the "{store}" segment of a
 * Route::domain('{store}.'.root_domain) route. But this middleware is also
 * attached to Livewire's own component-update endpoint (see
 * AppServiceProvider::boot()), which is registered once, globally, with no
 * domain constraint — the page that renders a Livewire component embeds a
 * *relative* update URL, so the browser's AJAX call naturally lands back on
 * whatever host served the page, but Laravel's router never captures a
 * "{store}" route parameter for it. In that case we fall back to reading
 * the subdomain straight off the Host header. A request on the bare root
 * domain (admin/marketing Livewire components) has no store subdomain at
 * all, which is a no-op here, not an error.
 */
class IdentifyStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('store') ?? $this->slugFromHost($request);

        if ($slug === null) {
            return $next($request);
        }

        $store = Store::where('slug', $slug)->first();

        if (! $store || $store->status === StoreStatus::Draft) {
            abort(404);
        }

        if ($store->status === StoreStatus::Suspended) {
            abort(503, 'This store is temporarily unavailable.');
        }

        app()->instance(Store::class, $store);
        View::share('currentStore', $store);

        // Every named route on this subdomain requires a "{store}" segment
        // (it's part of the domain pattern). Registering it as a URL
        // default means route(...) calls throughout the request don't all
        // need to pass ['store' => ...] explicitly — one less thing to get
        // wrong or forget, and it can never point at the wrong store since
        // it's set once, here, from the resolved tenant.
        URL::defaults(['store' => $store->slug]);

        return $next($request);
    }

    /**
     * Read the store slug off the Host header for requests (namely
     * Livewire's update endpoint) that aren't routed through the
     * "{store}." domain-pattern group and so never get a "store" route
     * parameter. Returns null for the bare root domain or any host that
     * isn't a "*.<root_domain>" subdomain.
     */
    private function slugFromHost(Request $request): ?string
    {
        $host = $request->getHost();
        $root = config('app.root_domain');

        if (! $root || $host === $root || ! Str::endsWith($host, ".{$root}")) {
            return null;
        }

        return Str::beforeLast($host, ".{$root}");
    }
}
