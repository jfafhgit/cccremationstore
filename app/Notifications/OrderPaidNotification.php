<?php

namespace App\Notifications;

use App\Enums\OrderTiming;
use App\Models\Order;
use App\Notifications\Concerns\FormatsOrderForMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The purchaser's confirmation and receipt, sent once when payment clears.
 * It comes from the funeral home (by name, and replies go to its general
 * email) and invites the family to share the fuller intake details.
 */
class OrderPaidNotification extends Notification implements ShouldQueue
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
            ->from(config('mail.from.address'), $this->senderNameFor($order))
            ->subject("Your order with {$store->name} is confirmed ({$order->order_number})")
            ->markdown('mail.orders.paid', [
                'frame' => $this->storeFrame(
                    $order,
                    storeUrl: route('storefront.start', ['store' => $store->slug]),
                    footer: $this->escapeMarkdown($store->name.($store->contact_phone ? ' · '.$store->contact_phone : ''))
                        ."\\\n".$this->poweredByFooter('Online arrangements powered by'),
                ),
                'storeName' => $this->escapeMarkdown($store->name),
                'purchaserFirstName' => $this->escapeMarkdown($order->purchaser_first_name),
                'deceasedName' => $this->escapeMarkdown($order->deceasedName()),
                'isImmediate' => $order->timing === OrderTiming::Immediate,
                'detailsUrl' => $order->detailsUrl(),
                'summary' => $this->orderSummary($order),
                'canReply' => filled($store->general_email),
                'contactPhone' => $store->contact_phone ? $this->escapeMarkdown($store->contact_phone) : null,
            ]);

        if ($store->general_email) {
            $message->replyTo($store->general_email, $store->name);
        }

        return $message;
    }
}
