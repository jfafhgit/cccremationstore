<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to super admins when someone signs in to the admin area for the first
 * time without an invitation, so the request can be approved or denied.
 */
class AdminAccessRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $requester) {}

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
            ->subject('Admin access requested — Planning by Treasured Memories')
            ->greeting('Someone is waiting for admin access.')
            ->line("Name: {$this->requester->name}")
            ->line("Email: {$this->requester->email}")
            ->line('They cannot see anything in the admin area until you approve them.')
            ->action('Review access requests', route('admin.users'));
    }
}
