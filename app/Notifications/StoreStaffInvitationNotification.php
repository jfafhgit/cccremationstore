<?php

namespace App\Notifications;

use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a funeral home staff member when a platform admin creates their
 * portal login. The link lets them choose their own password; nobody else
 * ever sees or handles it.
 */
class StoreStaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly StoreUser $storeUser,
        private readonly User $invitedBy,
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
        $storeName = $this->storeUser->store->name;

        return (new MailMessage)
            ->subject("You're invited to the {$storeName} staff portal")
            ->greeting("Hi {$this->storeUser->name},")
            ->line("{$this->invitedBy->name} from ".config('app.name')." has set up a staff login for you at {$storeName}.")
            ->line($this->storeUser->isOwner()
                ? 'As the account owner, you can see your online orders and manage billing.'
                : 'You can see and follow up on your online orders.')
            ->action('Choose your password', $this->storeUser->invitationUrl($this->token))
            ->line('This link can be used once and expires on '.$this->storeUser->invitationExpiresAt()->format('F j, Y').'. If it expires, ask us to send a new one.')
            ->line("If you weren't expecting this, you can ignore this email.");
    }
}
