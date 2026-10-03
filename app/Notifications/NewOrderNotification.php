<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\FormatsOrderForMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts a store's staff and its general email that a new paid order
 * came in. Replies go to the family.
 */
class NewOrderNotification extends Notification implements ShouldQueue
{
    use FormatsOrderForMail, Queueable;

    public function __construct(private readonly Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['store', 'items']);
        $store = $order->store;

        $message = (new MailMessage)
            ->subject("New order {$order->order_number} — {$order->deceasedName()}")
            ->markdown('mail.orders.new-for-staff', [
                'frame' => $this->storeFrame(
                    $order,
                    storeUrl: route('portal.orders', ['store' => $store->slug]),
                    footer: $this->poweredByFooter("Sent to {$store->name} staff by"),
                ),
                'storeName' => $this->escapeMarkdown($store->name),
                'deceasedName' => $this->escapeMarkdown($order->deceasedName()),
                'purchaserName' => $this->escapeMarkdown($order->purchaserName()),
                'relationship' => $order->relationship_to_deceased ? $this->escapeMarkdown($order->relationship_to_deceased) : null,
                'purchaserEmail' => $this->escapeMarkdown($order->purchaser_email),
                'purchaserPhone' => $order->purchaser_phone ? $this->escapeMarkdown($order->purchaser_phone) : null,
                'timing' => $order->timing?->staffLabel(),
                'summary' => $this->orderSummary($order),
                'portalUrl' => route('portal.order-detail', ['store' => $store->slug, 'order' => $order->id]),
                'usesExternalForm' => $store->usesExternalVitalStatistics(),
            ]);

        if ($order->purchaser_email) {
            $message->replyTo($order->purchaser_email, $order->purchaserName());
        }

        return $message;
    }
}
