<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Store;
use App\Notifications\OrderPaidNotification;
use App\Services\CheckoutService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;

/**
 * Receives Stripe Connect platform webhooks. Configure this single URL
 * (route('stripe.webhook')) in the Stripe Dashboard under Connect webhooks,
 * subscribed to at least: payment_intent.succeeded, account.updated.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout, StripeConnectService $stripeConnect): Response
    {
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = $secret
                ? Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret)
                : Event::constructFrom(json_decode($request->getContent(), true));
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['message' => $e->getMessage()]);

            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event, $checkout),
            'account.updated' => $this->handleAccountUpdated($event, $stripeConnect),
            default => null,
        };

        return response('ok');
    }

    private function handlePaymentIntentSucceeded(Event $event, CheckoutService $checkout): void
    {
        /** @var PaymentIntent $intent */
        $intent = $event->data->object;

        $order = Order::where('stripe_payment_intent_id', $intent->id)->first();

        if (! $order || $order->paid_at) {
            return;
        }

        $checkout->markPaid($order);

        if ($order->purchaser_email) {
            $order->notify(new OrderPaidNotification($order));
        }
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
