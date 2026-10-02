<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\StripeConnectService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stripe:register-payment-domains')]
#[Description('Register each Stripe-connected storefront domain for Apple Pay and Google Pay')]
class RegisterPaymentMethodDomains extends Command
{
    /**
     * New stores are registered automatically when they finish connecting
     * Stripe; this covers stores connected before that, and retries any
     * whose registration failed.
     */
    public function handle(StripeConnectService $stripeConnect): int
    {
        $stores = Store::whereNotNull('stripe_account_id')->where('stripe_charges_enabled', true)->get();

        foreach ($stores as $store) {
            $store = $stripeConnect->ensurePaymentMethodDomain($store);

            $store->stripe_payment_method_domain === $store->storefrontDomain()
                ? $this->components->info("Registered {$store->storefrontDomain()}")
                : $this->components->error("Could not register {$store->storefrontDomain()} (see the log)");
        }

        return self::SUCCESS;
    }
}
