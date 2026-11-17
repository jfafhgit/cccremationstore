<?php

namespace App\Notifications;

use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a staff member asks to reset their password from a store's
 * sign-in page. The link opens on that same store's subdomain.
 */
class StaffPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly StoreUser $storeUser,
        private readonly Store $store,
        private readonly string $token,
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
        $expiresInMinutes = config('auth.passwords.store_users.expire');

        return (new MailMessage)
            ->subject("Reset your {$this->store->name} staff portal password")
            ->greeting("Hi {$this->storeUser->name},")
            ->line("We received a request to reset the password for your {$this->store->name} staff login.")
            ->action('Choose a new password', $this->storeUser->passwordResetUrl($this->store, $this->token))
            ->line("This link expires in {$expiresInMinutes} minutes. If you have access to other locations, the new password works there too.")
            ->line("If you didn't ask to reset your password, you can ignore this email.");
    }
}
