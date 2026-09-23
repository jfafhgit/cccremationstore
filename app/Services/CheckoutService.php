<?php

namespace App\Services;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ProductCategory;
use App\Models\Order;
use App\Models\Store;
use App\Notifications\OrderPaidNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Turns a Cart plus the minimal purchaser/deceased details into an Order and
 * a Stripe PaymentIntent, charged directly on the store's own Stripe Connect
 * account with a platform application fee.
 */
class CheckoutService
{
    /**
     * PaymentIntent statuses in which the amount can still be changed and
     * the Payment Element can still be (re)mounted.
     *
     * @var array<int, string>
     */
    private const UPDATABLE_INTENT_STATUSES = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
    ];

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
        $this->assertRequiredSelectionsPresent($store, $cart);

        return DB::transaction(function () use ($store, $cart, $details, $source): Order {
            $order = Order::create([
                'store_id' => $store->id,
                'status' => OrderStatus::PendingPayment,
                'source' => $source,
                'currency' => 'usd',
                'stripe_account_id' => $store->stripe_account_id,
                ...$this->orderAttributesFor($store, $cart, $details),
            ]);

            $this->writeItems($order, $cart);

            return $order;
        });
    }

    /**
     * Bring a not-yet-paid order back in line with the cart and details the
     * customer is about to pay for. The wizard reuses one order per checkout
     * attempt, so if the family steps back and changes their selections (or
     * fixes a typo in their email), the order — and therefore the amount the
     * PaymentIntent charges — must follow.
     *
     * @param  array<string, mixed>  $details
     */
    public function updatePendingOrder(Order $order, Cart $cart, array $details): Order
    {
        if (! $order->isAwaitingPayment()) {
            throw new \LogicException("Order {$order->order_number} is no longer awaiting payment.");
        }

        $store = $order->store;

        $this->assertRequiredSelectionsPresent($store, $cart);

        return DB::transaction(function () use ($order, $store, $cart, $details): Order {
            $order->update($this->orderAttributesFor($store, $cart, $details));

            $order->items()->delete();
            $this->writeItems($order, $cart);

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function orderAttributesFor(Store $store, Cart $cart, array $details): array
    {
        $subtotalCents = $cart->subtotalCents();
        $taxCents = $cart->taxCents();
        $processingFeeCents = $cart->processingFeeCents();

        return [
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
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => $taxCents,
            'processing_fee_cents' => $processingFeeCents,
            // The platform's cut is of the store's own revenue only — sales tax
            // is a pass-through to the taxing authority, and the processing fee
            // covers the store's own card costs, neither is platform income.
            'platform_fee_cents' => $store->platformFeeCentsFor($subtotalCents),
            'total_cents' => $subtotalCents + $taxCents + $processingFeeCents,
        ];
    }

    private function writeItems(Order $order, Cart $cart): void
    {
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
    }

    /**
     * The checkout wizard already blocks progress past "Personalize" when a
     * required container/urn selection is missing, but that's client-side
     * state — this is the actual integrity boundary before an order (and a
     * charge) is created. Only enforced when the store actually sells
     * products in that category, matching what the wizard shows the customer.
     */
    private function assertRequiredSelectionsPresent(Store $store, Cart $cart): void
    {
        if ($store->requires_container && ! $cart->hasContainer() && $store->products()->active()->ofCategory(ProductCategory::Container)->exists()) {
            throw new \RuntimeException('A cremation container selection is required to complete this order.');
        }

        if ($store->requires_urn && ! $cart->hasUrn() && $store->products()->active()->ofCategory(ProductCategory::Urn)->exists()) {
            throw new \RuntimeException('An urn selection is required to complete this order.');
        }
    }

    /**
     * Create (or reuse) the PaymentIntent for this order and return the
     * client secret the frontend needs to mount Stripe's Payment Element.
     * A reused intent is updated first if the order's amounts have changed
     * since it was created, so what Stripe charges always matches the order
     * the customer is looking at.
     */
    public function createPaymentIntent(Order $order): string
    {
        $intent = $this->retrievePaymentIntent($order);

        if ($intent && in_array($intent->status, self::UPDATABLE_INTENT_STATUSES, true)) {
            if ($intent->amount !== $order->total_cents
                || $intent->application_fee_amount !== $order->platform_fee_cents
                || $intent->receipt_email !== $order->purchaser_email) {
                $intent = $this->client()->paymentIntents->update($intent->id, [
                    'amount' => $order->total_cents,
                    'application_fee_amount' => $order->platform_fee_cents,
                    'receipt_email' => $order->purchaser_email,
                ], ['stripe_account' => $order->stripe_account_id]);
            }

            return $intent->client_secret;
        }

        if ($intent && $intent->status !== PaymentIntent::STATUS_CANCELED) {
            // Succeeded or still processing: starting another charge could double-bill.
            throw new \RuntimeException("Order {$order->order_number} already has a payment in progress ({$intent->status}).");
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

    /**
     * The single path by which an order becomes paid. Re-reads the
     * PaymentIntent from Stripe rather than trusting whatever triggered the
     * check (a browser redirect, a webhook payload), so it is safe to call
     * from both, in either order, any number of times. Returns the intent's
     * current status, or null if the order has no intent yet.
     */
    public function syncPaymentStatus(Order $order): ?string
    {
        if ($order->paid_at) {
            return PaymentIntent::STATUS_SUCCEEDED;
        }

        $intent = $this->retrievePaymentIntent($order);

        if (! $intent) {
            return null;
        }

        if ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            return $intent->status;
        }

        if ($intent->amount_received < $order->total_cents || $intent->currency !== $order->currency) {
            Log::critical('Stripe payment does not match the order total; not marking it paid.', [
                'order_id' => $order->id,
                'payment_intent' => $intent->id,
                'amount_received' => $intent->amount_received,
                'order_total_cents' => $order->total_cents,
            ]);

            return $intent->status;
        }

        if ($this->markPaid($order) && $order->purchaser_email) {
            $order->notify(new OrderPaidNotification($order));
        }

        return $intent->status;
    }

    /**
     * Atomically flip the order to paid. Returns true only for the caller
     * that actually made the change, so a webhook and a browser redirect
     * racing each other can't both send the "payment received" email.
     */
    public function markPaid(Order $order): bool
    {
        $changed = Order::whereKey($order->id)
            ->whereNull('paid_at')
            ->update([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ]) === 1;

        $order->refresh();

        return $changed;
    }

    /**
     * Reflect a full refund issued from the store's own Stripe dashboard.
     */
    public function markRefunded(Order $order): void
    {
        if ($order->paid_at && $order->status !== OrderStatus::Refunded) {
            $order->update(['status' => OrderStatus::Refunded]);
        }
    }
}
