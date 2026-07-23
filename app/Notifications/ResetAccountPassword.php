<?php

namespace App\Notifications;

use App\Support\AccountActionUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetAccountPassword extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject(__('Reset your password'))
            ->line(__('We received a request to reset your account password.'))
            ->action(
                __('Reset password'),
                AccountActionUrl::make(
                    (string) config('auth_lifecycle.frontend_urls.password_reset'),
                    $this->token,
                ),
            )
            ->line(__('If you did not request a password reset, you can ignore this email.'));
    }
}
