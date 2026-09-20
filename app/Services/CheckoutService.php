<?php

namespace App\Services;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Turns a Cart plus the minimal purchaser/deceased details into an Order and
 * a Stripe PaymentIntent, charged directly on the store's own Stripe Connect
 * account with a platform application fee.
 */
class CheckoutService
{
    private ?StripeClient $client = null;

    /**
     * Built lazily so that simply injecting this service (e.g. via Livewire's
     * action-method dependency resolution) never blows up just because the
     * platform's Stripe secret key isn't configured yet — only actually
     * talking to Stripe does, and that path is wrapped in a try/catch.
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
     * @param  array<string, mixed>  $details
     */
    public function createOrder(Store $store, Cart $cart, array $details, OrderSource $source = OrderSource::Storefront): Order
    {
        $subtotalCents = $cart->subtotalCents();
        $taxCents = $cart->taxCents();
        $totalCents = $subtotalCents + $taxCents;
        // The platform's cut is of the store's own revenue only — sales tax
        // is a pass-through to the taxing authority, never platform income.
        $platformFeeCents = $store->platformFeeCentsFor($subtotalCents);

        return DB::transaction(function () use ($store, $cart, $details, $source, $subtotalCents, $taxCents, $totalCents, $platformFeeCents): Order {
            $order = Order::create([
                'store_id' => $store->id,
                'status' => OrderStatus::PendingPayment,
                'source' => $source,
                'timing' => $cart->timing(),
                'purchaser_first_name' => $details['purchaser_first_name'],
                'purchaser_last_name' => $details['purchaser_last_name'],
                'purchaser_email' => $details['purchaser_email'],
                'purchaser_phone' => $details['purchaser_phone'] ?? null,
                'relationship_to_deceased' => $details['relationship_to_deceased'],
                'deceased_first_name' => $details['deceased_first_name'],
                'deceased_middle_name' => $details['deceased_middle_name'] ?? null,
                'deceased_last_name' => $details['deceased_last_name'],
                'deceased_suffix' => $details['deceased_suffix'] ?? null,
                'currency' => 'usd',
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'platform_fee_cents' => $platformFeeCents,
                'total_cents' => $totalCents,
                'stripe_account_id' => $store->stripe_account_id,
            ]);

            foreach ($cart->allLines() as $line) {
                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['variant_id'] ?? null,
                    'category_snapshot' => $line['category'],
                    'is_taxable_snapshot' => $line['is_taxable'] ?? true,
                    'taxable_unit_cents_snapshot' => $cart->taxableUnitCentsFor($line),
                    'name_snapshot' => $line['name'],
                    'variant_snapshot' => $line['variant_name'] ?? null,
                    'unit_price_cents' => $line['unit_price_cents'],
                    'base_price_cents_snapshot' => $line['base_price_cents'] ?? 0,
                    'quantity' => $line['quantity'],
                    'total_price_cents' => $cart->lineTotalCents($line),
                ]);
            }

            return $order;
        });
    }

    /**
     * Create (or reuse) the PaymentIntent for this order and return the
     * client secret the frontend needs to mount Stripe's Payment Element.
     */
    public function createPaymentIntent(Order $order): string
    {
        if ($order->stripe_payment_intent_id) {
            $intent = $this->client()->paymentIntents->retrieve(
                $order->stripe_payment_intent_id,
                [],
                ['stripe_account' => $order->stripe_account_id],
            );

            return $intent->client_secret;
        }

        $intent = $this->client()->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => $order->currency,
            'application_fee_amount' => $order->platform_fee_cents,
            'automatic_payment_methods' => ['enabled' => true],
            'receipt_email' => $order->purchaser_email,
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'store_id' => (string) $order->store_id,
            ],
        ], ['stripe_account' => $order->stripe_account_id]);

        $order->update(['stripe_payment_intent_id' => $intent->id]);

        return $intent->client_secret;
    }

    public function retrievePaymentIntent(Order $order): ?PaymentIntent
    {
        if (! $order->stripe_payment_intent_id) {
            return null;
        }

        return $this->client()->paymentIntents->retrieve(
            $order->stripe_payment_intent_id,
            [],
            ['stripe_account' => $order->stripe_account_id],
        );
    }

    public function markPaid(Order $order): void
    {
        if ($order->paid_at) {
            return;
        }

        $order->update([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
