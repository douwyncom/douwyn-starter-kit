<?php

namespace App\Notifications;

use App\Support\AccountActionUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmAccountEmailChange extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.confirm_email_change.subject'))
            ->line(__('notifications.confirm_email_change.intro'))
            ->action(
                __('notifications.confirm_email_change.action'),
                AccountActionUrl::make(
                    (string) config('auth_lifecycle.frontend_urls.email_change'),
                    $this->token,
                ),
            )
            ->line(__('notifications.confirm_email_change.outro'));
    }
}
