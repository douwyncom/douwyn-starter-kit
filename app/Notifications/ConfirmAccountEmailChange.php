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
            ->subject(__('Confirm your new email address'))
            ->line(__('Confirm this address to finish changing your account email.'))
            ->action(
                __('Confirm email change'),
                AccountActionUrl::make(
                    (string) config('auth_lifecycle.frontend_urls.email_change'),
                    $this->token,
                ),
            )
            ->line(__('If you did not request this change, secure your account immediately.'));
    }
}
