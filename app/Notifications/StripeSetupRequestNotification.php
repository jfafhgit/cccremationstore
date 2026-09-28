<?php

namespace App\Notifications;

use App\Models\Store;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks a store owner to connect their own Stripe account. Stripe's
 * onboarding links expire within minutes and work once, so the email links
 * to the portal's Payments page instead, which makes a fresh one after the
 * owner signs in — whoever finishes onboarding decides where payouts go.
 */
class StripeSetupRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Store $store,
        private readonly User $requestedBy,
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
        $storeName = $this->store->name;

        return (new MailMessage)
            ->subject("Connect Stripe to receive payments for {$storeName}")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->requestedBy->name} from ".config('app.name')." is ready for {$storeName} to start taking online payments.")
            ->line('Online orders are paid straight into your own Stripe account. Sign in to your staff portal to create a new Stripe account or connect one you already have — it takes about ten minutes.')
            ->action('Set up payments', route('portal.payments', ['store' => $this->store->slug]))
            ->line('Have your business details, tax ID, and bank account information handy.');
    }
}
