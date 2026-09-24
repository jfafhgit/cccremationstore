<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to someone a super admin has invited into the admin area.
 */
class AdminInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $invitedBy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited — Planning by Treasured Memories')
            ->greeting('You have been invited to the admin area.')
            ->line("{$this->invitedBy->name} invited you to help manage Planning by Treasured Memories.")
            ->line('Sign in with the Google account for this email address to get started.')
            ->action('Sign in', route('login'));
    }
}
