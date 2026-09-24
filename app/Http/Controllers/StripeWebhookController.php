<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Store;
use App\Services\CheckoutService;
use App\Services\PlatformBillingService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Charge;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * Receives Stripe webhooks at a single URL (route('stripe.webhook')). In the
 * Stripe Dashboard, point two endpoints here:
 *  - "Connected accounts" (STRIPE_WEBHOOK_SECRET): payment_intent.succeeded,
 *    payment_intent.payment_failed, charge.refunded, account.updated.
 *  - "Your account" (STRIPE_PLATFORM_WEBHOOK_SECRET): checkout.session.completed,
 *    customer.subscription.created, customer.subscription.updated,
 *    customer.subscription.deleted.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout, StripeConnectService $stripeConnect, PlatformBillingService $billing): Response
    {
        $secrets = array_values(array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.platform_webhook_secret'),
        ]));

        if (! $secrets && ! app()->environment('local', 'testing')) {
            Log::error('Rejected a Stripe webhook: STRIPE_WEBHOOK_SECRET is not configured.');

            return response('Webhook secret not configured', 500);
        }

        $event = $this->constructEvent($request, $secrets);

        if (! $event) {
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded',
            'payment_intent.payment_failed' => $this->handlePaymentIntentEvent($event, $checkout),
            'charge.refunded' => $this->handleChargeRefunded($event, $checkout),
            'account.updated' => $this->handleAccountUpdated($event, $stripeConnect),
            'checkout.session.completed' => $this->handleCheckoutCompleted($event, $billing),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionEvent($event, $billing),
            default => null,
        };

        return response('ok');
    }

    /**
     * Verify the payload against whichever endpoint's secret signed it.
     *
     * @param  array<int, string>  $secrets
     */
    private function constructEvent(Request $request, array $secrets): ?Event
    {
        try {
            if (! $secrets) {
                return Event::constructFrom(json_decode($request->getContent(), true));
            }

            foreach ($secrets as $secret) {
                try {
                    return Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret);
                } catch (SignatureVerificationException) {
                    continue;
                }
            }
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook payload could not be parsed.', ['message' => $e->getMessage()]);

            return null;
        }

        Log::warning('Stripe webhook signature verification failed.');

        return null;
    }

    private function handleCheckoutCompleted(Event $event, PlatformBillingService $billing): void
    {
        /** @var CheckoutSession $session */
        $session = $event->data->object;

        // Only the platform's own subscription checkouts — never a connected
        // account's own Checkout sessions.
        if ($event->account !== null || $session->mode !== 'subscription') {
            return;
        }

        if ($store = Store::find($session->metadata['store_id'] ?? null)) {
            $billing->completeCheckout($store, $session->id);
        }
    }

    private function handleSubscriptionEvent(Event $event, PlatformBillingService $billing): void
    {
        /** @var Subscription $subscription */
        $subscription = $event->data->object;

        // A connected account's own subscriptions are none of our business.
        if ($event->account !== null) {
            return;
        }

        $store = Store::where('stripe_subscription_id', $subscription->id)->first();

        // The subscription can be reported before the checkout completion
        // that links it to the store has been processed.
        if (! $store && ($storeId = $subscription->metadata['store_id'] ?? null)) {
            $store = Store::whereKey($storeId)->whereNull('stripe_subscription_id')->first();
            $store?->forceFill(['stripe_subscription_id' => $subscription->id])->save();
        }

        if ($store) {
            $billing->syncSubscription($store);
        }
    }

    private function handlePaymentIntentEvent(Event $event, CheckoutService $checkout): void
    {
        /** @var PaymentIntent $intent */
        $intent = $event->data->object;

        $order = $this->orderFor($event, $intent->id);

        if (! $order) {
            return;
        }

        if ($event->type === 'payment_intent.payment_failed') {
            // The order simply stays pending; the family can retry from
            // the checkout or the return page.
            Log::info('Stripe payment attempt failed.', [
                'order_id' => $order->id,
                'reason' => $intent->last_payment_error?->message,
            ]);

            return;
        }

        $checkout->syncPaymentStatus($order);
    }

    private function handleChargeRefunded(Event $event, CheckoutService $checkout): void
    {
        /** @var Charge $charge */
        $charge = $event->data->object;

        if (! $charge->refunded || ! is_string($charge->payment_intent)) {
            return; // Partial refunds leave the order as-is.
        }

        if ($order = $this->orderFor($event, $charge->payment_intent)) {
            $checkout->markRefunded($order);
        }
    }

    /**
     * Only act on an event if it came from the same connected account the
     * order was charged on.
     */
    private function orderFor(Event $event, string $paymentIntentId): ?Order
    {
        $order = Order::where('stripe_payment_intent_id', $paymentIntentId)->first();

        if ($order && $event->account !== $order->stripe_account_id) {
            Log::warning('Ignored a Stripe webhook whose connected account does not match the order.', [
                'order_id' => $order->id,
                'event_account' => $event->account,
            ]);

            return null;
        }

        return $order;
    }

    private function handleAccountUpdated(Event $event, StripeConnectService $stripeConnect): void
    {
        /** @var Account $account */
        $account = $event->data->object;

        $store = Store::where('stripe_account_id', $account->id)->first();

        if ($store) {
            $stripeConnect->syncAccountStatus($store);
        }
    }
}
