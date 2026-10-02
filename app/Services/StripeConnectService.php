<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Exception\ApiErrorException;
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
     * The email pre-fills Stripe's form; it defaults to the store's contact
     * email, but an owner onboarding themselves passes their own.
     */
    public function ensureAccount(Store $store, ?string $email = null): Store
    {
        if ($store->stripe_account_id) {
            return $store;
        }

        $account = $this->client()->accounts->create([
            'type' => 'standard',
            'email' => $email ?? $store->contact_email,
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

    public function createOnboardingLink(Store $store, string $returnUrl, string $refreshUrl, ?string $email = null): AccountLink
    {
        $store = $this->ensureAccount($store, $email);

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

        return $this->ensurePaymentMethodDomain($store->fresh());
    }

    /**
     * Register the storefront's domain with the store's own Stripe account,
     * which Stripe requires (per domain, per account, for direct charges)
     * before Apple Pay and Google Pay can appear in the Payment Element.
     * Remembered on the store, so it runs once per domain — and again after
     * a subdomain change. A failure is logged rather than thrown: card
     * payments still work without it, just without the wallets.
     */
    public function ensurePaymentMethodDomain(Store $store): Store
    {
        $domain = $store->storefrontDomain();

        if (! $store->stripe_account_id || ! $store->stripe_charges_enabled || $store->stripe_payment_method_domain === $domain) {
            return $store;
        }

        $options = ['stripe_account' => $store->stripe_account_id];

        try {
            $existing = $this->client()->paymentMethodDomains->all(['domain_name' => $domain, 'limit' => 1], $options)->data[0] ?? null;

            if (! $existing) {
                $this->client()->paymentMethodDomains->create(['domain_name' => $domain], $options);
            } elseif (! $existing->enabled) {
                $this->client()->paymentMethodDomains->update($existing->id, ['enabled' => true], $options);
            }
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::warning('Could not register the storefront domain for Apple Pay / Google Pay.', [
                'store_id' => $store->id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return $store;
        }

        $store->forceFill(['stripe_payment_method_domain' => $domain])->save();

        return $store;
    }
}
