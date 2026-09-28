<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\FormatsOrderForMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts a store's staff that a family has submitted (or resubmitted) its
 * completed Vital Statistics, with the answers themselves so staff can read
 * them from their phone. The Social Security number is only ever shown
 * masked; the full number stays in the staff portal. Replies go to the family.
 */
class OrderDetailsSubmittedNotification extends Notification implements ShouldQueue
{
    use FormatsOrderForMail, Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly bool $isUpdate = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['store', 'detail.order']);
        $store = $order->store;
        $detail = $order->detail;

        $message = (new MailMessage)
            ->subject('Vital Statistics '.($this->isUpdate ? 'updated' : 'received')." for {$order->deceasedName()} ({$order->order_number})")
            ->markdown('mail.orders.details-for-staff', [
                'frame' => $this->storeFrame(
                    $order,
                    storeUrl: route('portal.orders', ['store' => $store->slug]),
                    footer: $this->poweredByFooter("Sent to {$store->name} staff by"),
                ),
                'isUpdate' => $this->isUpdate,
                'orderNumber' => $order->order_number,
                'deceasedName' => $this->escapeMarkdown($order->deceasedName()),
                'purchaserName' => $this->escapeMarkdown($order->purchaserName()),
                'hasPacemaker' => $detail?->has_pacemaker === true,
                'sections' => array_map(
                    fn (array $answers): array => array_map($this->escapeMarkdown(...), $answers),
                    $detail?->sections() ?? [],
                ),
                'obituary' => $detail?->obituary_text ? $this->escapeMarkdownBlock($detail->obituary_text) : null,
                'servicePreferences' => $detail?->service_preferences ? $this->escapeMarkdownBlock($detail->service_preferences) : null,
                'additionalNotes' => $detail?->additional_notes ? $this->escapeMarkdownBlock($detail->additional_notes) : null,
                'portalUrl' => route('portal.order-detail', ['store' => $store->slug, 'order' => $order->id]),
            ]);

        if ($order->purchaser_email) {
            $message->replyTo($order->purchaser_email, $order->purchaserName());
        }

        return $message;
    }
}
