<?php

namespace App\Notifications;

use App\Models\OrderRefund;
use App\Notifications\Concerns\FormatsOrderForMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts a store's staff and its general email that one of its orders was
 * refunded, by the platform or from the store's own Stripe dashboard.
 */
class RefundIssuedForStaffNotification extends Notification implements ShouldQueue
{
    use FormatsOrderForMail, Queueable;

    public function __construct(private readonly OrderRefund $refund) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->refund->order->loadMissing('store');
        $store = $order->store;

        return (new MailMessage)
            ->subject("Refund issued on order {$order->order_number} — {$order->deceasedName()}")
            ->markdown('mail.orders.refunded-for-staff', [
                'frame' => $this->storeFrame(
                    $order,
                    storeUrl: route('portal.orders', ['store' => $store->slug]),
                    footer: $this->poweredByFooter("Sent to {$store->name} staff by"),
                ),
                'deceasedName' => $this->escapeMarkdown($order->deceasedName()),
                'purchaserName' => $this->escapeMarkdown($order->purchaserName()),
                'orderNumber' => $order->order_number,
                'amount' => '$'.$this->refund->amountInDollars(),
                'refundedSoFar' => '$'.number_format($order->refundedCents() / 100, 2),
                'total' => '$'.$order->totalInDollars(),
                'platformFeeReturned' => $this->refund->application_fee_refunded_cents > 0
                    ? '$'.number_format($this->refund->application_fee_refunded_cents / 100, 2)
                    : null,
                'issuedFrom' => $this->refund->refunded_by_user_id ? config('app.name') : 'Your Stripe dashboard',
                'reason' => $this->refund->reason ? $this->escapeMarkdown($this->refund->reason) : null,
                'portalUrl' => route('portal.order-detail', ['store' => $store->slug, 'order' => $order->id]),
            ]);
    }
}
