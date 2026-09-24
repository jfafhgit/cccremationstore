<?php

namespace App\Services;

use App\Enums\PlatformFeeModel;
use App\Models\Store;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Bills funeral homes on the subscription fee model through Stripe Billing on
 * the platform's own Stripe account. This is entirely separate from the
 * store's connected account (StripeConnectService), which is where families'
 * payments go.
 */
class PlatformBillingService
{
    private ?StripeClient $client = null;

    /**
     * Built lazily so injecting this service never fails just because the
     * platform's Stripe secret key isn't configured yet — only actually
     * talking to Stripe does.
     */
    private function client(): StripeClient
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
     * Start a Stripe-hosted Checkout for the store's monthly subscription and
     * return its URL. The price is created inline from the amount set in
     * admin, so no products or prices need to be set up in Stripe first.
     * Stripe replaces {CHECKOUT_SESSION_ID} in the success URL itself.
     */
    public function createCheckoutUrl(Store $store, string $successUrl, string $cancelUrl): string
    {
        if ($store->platform_fee_model !== PlatformFeeModel::Subscription || $store->subscription_monthly_cents <= 0) {
            throw new \RuntimeException('This store is not set up for a monthly subscription.');
        }

        if ($store->hasLiveSubscription()) {
            throw new \RuntimeException('This store already has an active subscription.');
        }

        $store = $this->ensureCustomer($store);

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $store->stripe_customer_id,
            'client_reference_id' => (string) $store->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $store->subscription_monthly_cents,
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => config('app.name').' platform subscription'],
                ],
            ]],
            'metadata' => ['store_id' => (string) $store->id],
            'subscription_data' => ['metadata' => ['store_id' => (string) $store->id]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return $session->url;
    }

    /**
     * Record the subscription a completed Checkout created. Called both from
     * the portal page Stripe returns to and from the checkout.session.completed
     * webhook, whichever arrives first; safe to call more than once.
     */
    public function completeCheckout(Store $store, string $checkoutSessionId): Store
    {
        $session = $this->client()->checkout->sessions->retrieve($checkoutSessionId);

        // A session id arrives in a URL, so make sure it's this store's own.
        if ($session->client_reference_id !== (string) $store->id) {
            throw new \RuntimeException('That checkout session does not belong to this store.');
        }

        if ($session->mode !== 'subscription' || $session->status !== 'complete' || ! is_string($session->subscription)) {
            return $store;
        }

        $store->forceFill([
            'stripe_customer_id' => $session->customer,
            'stripe_subscription_id' => $session->subscription,
        ])->save();

        return $this->syncSubscription($store);
    }

    /**
     * Refresh the store's cached subscription status from Stripe.
     */
    public function syncSubscription(Store $store): Store
    {
        if (! $store->stripe_subscription_id) {
            return $store;
        }

        $subscription = $this->retrieveSubscription($store);

        $store->forceFill(['subscription_status' => $subscription->status])->save();

        return $store->fresh();
    }

    /**
     * Bring a live subscription in line with what's set in admin: a changed
     * monthly amount takes effect from the next bill (no proration), and
     * moving the store to another fee model cancels at the end of the
     * already-paid period, so the funeral home is never billed both ways.
     */
    public function applyStoreTerms(Store $store): Store
    {
        if (! $store->hasLiveSubscription()) {
            return $store;
        }

        $subscription = $this->retrieveSubscription($store);

        if ($store->platform_fee_model !== PlatformFeeModel::Subscription) {
            if (! $subscription->cancel_at_period_end) {
                $this->client()->subscriptions->update($subscription->id, ['cancel_at_period_end' => true]);
            }

            return $this->syncSubscription($store);
        }

        $item = $subscription->items->data[0];
        $changes = [];

        if ($item->price->unit_amount !== $store->subscription_monthly_cents) {
            $changes['items'] = [[
                'id' => $item->id,
                'price_data' => [
                    'currency' => 'usd',
                    'product' => is_string($item->price->product) ? $item->price->product : $item->price->product->id,
                    'unit_amount' => $store->subscription_monthly_cents,
                    'recurring' => ['interval' => 'month'],
                ],
            ]];
            $changes['proration_behavior'] = 'none';
        }

        // Moved off the subscription model and then back again before the
        // period ended — keep billing rather than letting it lapse.
        if ($subscription->cancel_at_period_end) {
            $changes['cancel_at_period_end'] = false;
        }

        if ($changes) {
            $this->client()->subscriptions->update($subscription->id, $changes);
        }

        return $this->syncSubscription($store);
    }

    /**
     * Stripe's hosted billing portal, where the funeral home can update its
     * card and download invoices.
     */
    public function billingPortalUrl(Store $store, string $returnUrl): string
    {
        if (! $store->stripe_customer_id) {
            throw new \RuntimeException('This store has no billing account yet.');
        }

        return $this->client()->billingPortal->sessions->create([
            'customer' => $store->stripe_customer_id,
            'return_url' => $returnUrl,
        ])->url;
    }

    private function ensureCustomer(Store $store): Store
    {
        if ($store->stripe_customer_id) {
            return $store;
        }

        $customer = $this->client()->customers->create(array_filter([
            'name' => $store->name,
            'email' => $store->contact_email,
            'metadata' => ['store_id' => (string) $store->id, 'store_slug' => $store->slug],
        ]));

        $store->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $store;
    }

    private function retrieveSubscription(Store $store): Subscription
    {
        return $this->client()->subscriptions->retrieve($store->stripe_subscription_id);
    }
}
