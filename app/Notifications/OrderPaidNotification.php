<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaidNotification extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject("Payment received — {$order->store->name}")
            ->greeting("Thank you, {$order->purchaser_first_name}.")
            ->line("We've received your payment for {$order->deceasedName()} with {$order->store->name}.")
            ->line("Order number: {$order->order_number}")
            ->line('To help us take the best possible care of your family, please take a few minutes to share some additional information.')
            ->action('Continue with additional details', $order->detailsUrl())
            ->line('If you have any questions, please contact us directly — we are here to help.');
    }
}
