<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use App\Notifications\RefundIssuedForStaffNotification;
use App\Notifications\RefundIssuedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Refunds an order's payment on the store's connected Stripe account, and
 * keeps the order's refund history in step with Stripe.
 */
class RefundService
{
    private ?StripeClient $client = null;

    /**
     * Built lazily, like CheckoutService's, so injecting this service never
     * fails just because the platform's Stripe key isn't configured.
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
     * Refund part or all of what's left of the order's payment, optionally
     * returning whatever is left of the platform's application fee too.
     *
     * @throws \InvalidArgumentException when the amount isn't refundable
     */
    public function refund(Order $order, int $amountCents, ?string $reason, User $refundedBy, bool $returnPlatformFee = false): OrderRefund
    {
        $refund = DB::transaction(function () use ($order, $amountCents, $reason, $refundedBy, $returnPlatformFee): OrderRefund {
            // Locking the order stops two refunds racing past the remaining-balance check.
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($amountCents <= 0 || $amountCents > $order->refundableCents()) {
                throw new \InvalidArgumentException('The refund must be more than $0 and no more than what is left to refund.');
            }

            $stripeRefund = $this->client()->refunds->create([
                'payment_intent' => $order->stripe_payment_intent_id,
                'amount' => $amountCents,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                ],
            ], ['stripe_account' => $order->stripe_account_id]);

            $refund = $this->record($order, $stripeRefund);
            $refund->update([
                'reason' => $reason ?: null,
                'refunded_by_user_id' => $refundedBy->id,
                'application_fee_refunded_cents' => $returnPlatformFee && $order->platform_fee_cents > 0 ? $this->returnPlatformFee($order) : 0,
            ]);

            $this->markRefundedIfNothingLeft($order);

            return $refund;
        });

        // Already announced if Stripe's webhook happened to record it first.
        if ($refund->wasRecentlyCreated) {
            $this->notifyIssued($refund);
        }

        return $refund;
    }

    /**
     * Bring the order's refunds up to date with Stripe, picking up any
     * issued from the store's own Stripe dashboard.
     */
    public function syncFromStripe(Order $order): void
    {
        if (! $order->stripe_payment_intent_id) {
            return;
        }

        $stripeRefunds = $this->client()->refunds->all(
            ['payment_intent' => $order->stripe_payment_intent_id, 'limit' => 100],
            ['stripe_account' => $order->stripe_account_id],
        );

        $newRefunds = [];

        foreach ($stripeRefunds->autoPagingIterator() as $stripeRefund) {
            $refund = $this->record($order, $stripeRefund);

            if ($refund->wasRecentlyCreated) {
                $newRefunds[] = $refund;
            }
        }

        $this->markRefundedIfNothingLeft($order);

        foreach ($newRefunds as $refund) {
            $this->notifyIssued($refund);
        }
    }

    /**
     * Return what's left of the platform's application fee on the order's
     * charge, from the platform's own balance. A failure here is logged
     * rather than thrown, since the family's refund has already gone out.
     *
     * @return int the amount returned, in cents
     */
    private function returnPlatformFee(Order $order): int
    {
        try {
            $intent = $this->client()->paymentIntents->retrieve(
                $order->stripe_payment_intent_id,
                ['expand' => ['latest_charge']],
                ['stripe_account' => $order->stripe_account_id],
            );

            // Expanded above, so this is the Charge itself rather than its ID.
            $charge = $intent->latest_charge;
            $feeId = $charge instanceof Charge ? $charge->application_fee : null;

            if (! is_string($feeId)) {
                return 0;
            }

            $fee = $this->client()->applicationFees->retrieve($feeId);
            $remainingCents = $fee->amount - $fee->amount_refunded;

            if ($remainingCents <= 0) {
                return 0;
            }

            $this->client()->applicationFees->createRefund($feeId, ['amount' => $remainingCents]);

            return $remainingCents;
        } catch (ApiErrorException $e) {
            Log::error('Refunded an order but could not return the platform fee.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function record(Order $order, Refund $stripeRefund): OrderRefund
    {
        return OrderRefund::updateOrCreate(
            ['stripe_refund_id' => $stripeRefund->id],
            [
                'order_id' => $order->id,
                'amount_cents' => $stripeRefund->amount,
                'status' => $stripeRefund->status,
            ],
        );
    }

    private function markRefundedIfNothingLeft(Order $order): void
    {
        if ($order->paid_at && $order->status !== OrderStatus::Refunded && $order->refundableCents() === 0) {
            $order->update(['status' => OrderStatus::Refunded]);
        }
    }

    /**
     * Let the family and the funeral home know, unless the refund failed outright.
     */
    private function notifyIssued(OrderRefund $refund): void
    {
        if (! in_array($refund->status, OrderRefund::COUNTED_STATUSES, true)) {
            return;
        }

        $order = $refund->order;

        if ($order->purchaser_email) {
            $order->notify(new RefundIssuedNotification($refund));
        }

        $order->store->notifyStaff(new RefundIssuedForStaffNotification($refund));
    }
}
