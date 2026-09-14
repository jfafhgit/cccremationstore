<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the platform admin when a funeral home submits the "request a
 * demo" contact form on the root marketing site.
 */
class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;

        return (new MailMessage)
            ->subject('New funeral home inquiry — Planning by Treasured Memories')
            ->greeting('New inquiry from the website.')
            ->line("Name: {$lead->name}")
            ->line("Funeral home: {$lead->funeral_home_name}")
            ->line("Email: {$lead->email}")
            ->line("Phone: {$lead->phone}")
            ->line("Message: {$lead->message}");
    }
}
