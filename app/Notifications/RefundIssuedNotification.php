<?php

namespace App\Notifications;

use App\Models\OrderRefund;
use App\Notifications\Concerns\FormatsOrderForMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the purchaser a refund is on its way back to their card. Like the
 * order confirmation, it comes from the funeral home by name. The refund's
 * reason is internal, so it isn't included.
 */
class RefundIssuedNotification extends Notification implements ShouldQueue
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

        $message = (new MailMessage)
            ->from(config('mail.from.address'), $this->senderNameFor($order))
            ->subject("Your refund from {$store->name} ({$order->order_number})")
            ->markdown('mail.orders.refunded', [
                'frame' => $this->storeFrame(
                    $order,
                    storeUrl: route('storefront.start', ['store' => $store->slug]),
                    footer: $this->escapeMarkdown($store->name.($store->contact_phone ? ' · '.$store->contact_phone : ''))
                        ."\\\n".$this->poweredByFooter('Online arrangements powered by'),
                ),
                'storeName' => $this->escapeMarkdown($store->name),
                'purchaserFirstName' => $this->escapeMarkdown($order->purchaser_first_name),
                'orderNumber' => $order->order_number,
                'amount' => '$'.$this->refund->amountInDollars(),
                'refundedSoFar' => '$'.number_format($order->refundedCents() / 100, 2),
                'total' => '$'.$order->totalInDollars(),
                'isPartial' => $order->refundableCents() > 0,
                'canReply' => filled($store->general_email),
                'contactPhone' => $store->contact_phone ? $this->escapeMarkdown($store->contact_phone) : null,
            ]);

        if ($store->general_email) {
            $message->replyTo($store->general_email, $store->name);
        }

        return $message;
    }
}
