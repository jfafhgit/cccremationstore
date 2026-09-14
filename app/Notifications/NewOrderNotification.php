<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a store's staff (or its contact_email, if it has no staff yet)
 * when a new paid order comes in.
 */
class NewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $order = $this->order;

        $portalUrl = route('portal.order-detail', ['store' => $order->store->slug, 'order' => $order->id]);

        return (new MailMessage)
            ->subject("New order — {$order->order_number}")
            ->greeting('You have a new order.')
            ->line("Purchaser: {$order->purchaserName()} ({$order->purchaser_email})")
            ->line("For: {$order->deceasedName()}")
            ->line("Total: \${$order->totalInDollars()}")
            ->action('View order', $portalUrl);
    }
}
