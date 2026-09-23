<?php

namespace App\Services;

use App\Models\Store;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Exception\InvalidRequestException;
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

        $store->forceFill(['stripe_account_id' => $account->id])->save();

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
     * Unlink a connected account whose onboarding was never finished — e.g.
     * one created with the wrong email, which Stripe then won't let the
     * merchant change — so the next "Connect Stripe" starts over with a new
     * account using the store's current contact email. Re-checks with Stripe
     * first and refuses once details have been submitted, since that account
     * may already be the merchant's real one.
     */
    public function resetUnfinishedAccount(Store $store): Store
    {
        try {
            $store = $this->syncAccountStatus($store);
        } catch (InvalidRequestException $e) {
            // Already deleted from the Stripe dashboard — nothing to protect.
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }
        }

        if ($store->stripe_details_submitted) {
            throw new \RuntimeException('This Stripe account has already been onboarded and cannot be reset.');
        }

        $store->forceFill([
            'stripe_account_id' => null,
            'stripe_details_submitted' => false,
            'stripe_charges_enabled' => false,
            'stripe_payouts_enabled' => false,
        ])->save();

        return $store->fresh();
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

        $store->forceFill([
            'stripe_details_submitted' => (bool) $account->details_submitted,
            'stripe_charges_enabled' => (bool) $account->charges_enabled,
            'stripe_payouts_enabled' => (bool) $account->payouts_enabled,
        ])->save();

        return $store->fresh();
    }
}
