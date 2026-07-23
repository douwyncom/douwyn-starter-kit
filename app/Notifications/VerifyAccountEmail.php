<?php

namespace App\Notifications;

use App\Support\AccountActionUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyAccountEmail extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject(__('Verify your email address'))
            ->line(__('Confirm that this email address belongs to your account.'))
            ->action(
                __('Verify email address'),
                AccountActionUrl::make(
                    (string) config('auth_lifecycle.frontend_urls.email_verification'),
                    $this->token,
                ),
            )
            ->line(__('If you did not create this account, you can ignore this email.'));
    }
}
