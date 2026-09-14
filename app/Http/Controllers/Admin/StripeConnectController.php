<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StripeConnectService;
use Illuminate\Http\RedirectResponse;

class StripeConnectController extends Controller
{
    public function __construct(private readonly StripeConnectService $stripeConnect) {}

    public function connect(Store $store): RedirectResponse
    {
        $link = $this->stripeConnect->createOnboardingLink(
            $store,
            returnUrl: route('admin.stores.stripe.return', $store),
            refreshUrl: route('admin.stores.stripe.refresh', $store),
        );

        return redirect()->away($link->url);
    }

    public function refresh(Store $store): RedirectResponse
    {
        return $this->connect($store);
    }

    public function return(Store $store): RedirectResponse
    {
        $this->stripeConnect->syncAccountStatus($store);

        return redirect()->route('admin.stores.show', $store)
            ->with('status', 'Stripe account status updated.');
    }
}
