<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Store;
use App\Services\CheckoutService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Charge;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;

/**
 * Receives Stripe Connect platform webhooks. Configure this single URL
 * (route('stripe.webhook')) in the Stripe Dashboard under Connect webhooks,
 * subscribed to at least: payment_intent.succeeded,
 * payment_intent.payment_failed, charge.refunded, account.updated.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout, StripeConnectService $stripeConnect): Response
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret && ! app()->environment('local', 'testing')) {
            Log::error('Rejected a Stripe webhook: STRIPE_WEBHOOK_SECRET is not configured.');

            return response('Webhook secret not configured', 500);
        }

        try {
            $event = $secret
                ? Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret)
                : Event::constructFrom(json_decode($request->getContent(), true));
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['message' => $e->getMessage()]);

            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded',
            'payment_intent.payment_failed' => $this->handlePaymentIntentEvent($event, $checkout),
            'charge.refunded' => $this->handleChargeRefunded($event, $checkout),
            'account.updated' => $this->handleAccountUpdated($event, $stripeConnect),
            default => null,
        };

        return response('ok');
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
