<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public Message $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $author = $this->message->user;
        $url = url('/account/messages/' . $this->message->conversation_id);

        return (new MailMessage)
            ->subject('New message from ' . ($author?->name ?? 'SBN'))
            ->line($author?->name . ' sent you a message:')
            ->line('"' . str($this->message->body)->limit(180) . '"')
            ->action('Open conversation', $url);
    }
}
