<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TwoFactorCodeMail extends Mailable
{
    public function __construct(
        private readonly string $code,
        private readonly int $expirationMinutes,
        private readonly string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.two_factor.subject'));
    }

    public function content(): Content
    {
        $context = match (true) {
            $this->purpose === 'login', str_starts_with($this->purpose, 'auth_challenge.') => 'login',
            str_starts_with($this->purpose, 'two_factor_setup.') => 'setup',
            $this->purpose === 'account_security.current', str_starts_with($this->purpose, 'sensitive_action.') => 'security',
            default => 'verification',
        };

        return new Content(
            view: 'mail.auth.two-factor-code',
            text: 'mail.auth.two-factor-code-text',
            with: [
                'code' => $this->code,
                'expirationMinutes' => $this->expirationMinutes,
                'contextLabel' => __("notifications.two_factor.contexts.$context"),
                'intro' => __("notifications.two_factor.intros.$context"),
                'locale' => app()->getLocale(),
                'brandName' => (string) config('mail.branding.name', 'Douwyn'),
                'brandUrl' => (string) config('mail.branding.url', 'https://douwyn.com'),
                'supportAddress' => (string) config('mail.branding.support_address', 'contact@douwyn.com'),
                'logoPath' => public_path('logo.png'),
            ],
        );
    }
}
