<?php

namespace App\Services;

use App\Models\Store;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\StripeClient;

/**
 * Handles onboarding each funeral home's own Stripe Connect (Standard)
 * account. Money for that store's orders is charged directly onto this
 * account (a "direct charge"), with an application fee sent to the
 * platform account — see CheckoutService.
 */
class StripeConnectService
{
    private ?StripeClient $client = null;

    /**
     * Built lazily so injecting this service never fails just because the
     * platform's Stripe secret key isn't configured yet — only actually
     * talking to Stripe does.
     */
    public function client(): StripeClient
    {
        if (! $this->client) {
            $secret = config('services.stripe.secret');

            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('The platform Stripe secret key (STRIPE_SECRET_KEY) is not configured.');
            }

            $this->client = new StripeClient($secret);
        }

        return $this->client;
    }

    /**
     * Ensure the store has a Stripe Connect account, creating one if needed.
     */
    public function ensureAccount(Store $store): Store
    {
        if ($store->stripe_account_id) {
            return $store;
        }

        $account = $this->client()->accounts->create([
            'type' => 'standard',
            'email' => $store->contact_email,
            'business_type' => 'company',
            'company' => array_filter([
                'name' => $store->name,
            ]),
            'metadata' => [
                'store_id' => (string) $store->id,
                'store_slug' => $store->slug,
            ],
        ]);

        $store->update(['stripe_account_id' => $account->id]);

        return $store->fresh();
    }

    public function createOnboardingLink(Store $store, string $returnUrl, string $refreshUrl): AccountLink
    {
        $store = $this->ensureAccount($store);

        return $this->client()->accountLinks->create([
            'account' => $store->stripe_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]);
    }

    public function retrieveAccount(Store $store): ?Account
    {
        if (! $store->stripe_account_id) {
            return null;
        }

        return $this->client()->accounts->retrieve($store->stripe_account_id);
    }

    /**
     * Refresh the store's cached Stripe account status. Call this after the
     * merchant returns from onboarding, and from the account.updated webhook.
     */
    public function syncAccountStatus(Store $store): Store
    {
        $account = $this->retrieveAccount($store);

        if (! $account) {
            return $store;
        }

        $store->update([
            'stripe_details_submitted' => (bool) $account->details_submitted,
            'stripe_charges_enabled' => (bool) $account->charges_enabled,
            'stripe_payouts_enabled' => (bool) $account->payouts_enabled,
        ]);

        return $store->fresh();
    }
}
