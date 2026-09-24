<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to someone a super admin has invited into the platform admin area.
 * Admins sign in with Google through WorkOS, so there is no password to
 * choose: signing in with this email address claims the invitation.
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
        $appName = config('app.name');

        return (new MailMessage)
            ->subject("You're invited to the {$appName} admin area")
            ->greeting('Hi there,')
            ->line("{$this->invitedBy->name} has invited you to help manage {$appName}.")
            ->line('As an admin, you can set up funeral home stores, their products, and their staff logins.')
            ->action('Sign in with Google', route('login'))
            ->line("Sign in with the Google account for {$notifiable->email}. Your access is already approved.")
            ->line("If you weren't expecting this, you can ignore this email.");
    }
}
