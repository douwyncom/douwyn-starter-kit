<?php

declare(strict_types=1);

use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Support\EmailTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

uses(RefreshDatabase::class);

it('delivers a branded multipart OTP in the recipients language and preserves single-use verification', function (string $locale): void {
    $user = User::factory()->create();
    $user->profile->update(['locale' => $locale]);
    app()->setLocale($locale === 'vi' ? 'en' : 'vi');
    $previousLocale = app()->getLocale();
    $purpose = 'auth_challenge.private-challenge-identifier';
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $transport->flush();

    EmailTwoFactor::send($user->uuid, $user->email, $purpose);

    $message = $transport->messages()->sole()->getOriginalMessage();
    $html = $message->getHtmlBody();
    preg_match('/data-otp-code[^>]*>(\d{6})<\/span>/', $html, $matches);
    $code = $matches[1] ?? '';
    $record = TwoFactorCode::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($html)->toContain('lang="'.$locale.'"', '#f4f4f0', 'cid:', 'mailto:contact@douwyn.com', 'https://douwyn.com')
        ->toContain(trans('notifications.two_factor.intros.login', locale: $locale))
        ->not->toContain($purpose)
        ->and($message->getTextBody())->toContain(trans('notifications.two_factor.body', ['code' => $code, 'minutes' => 5], $locale))
        ->and($message->getSubject())->toBe(trans('notifications.two_factor.subject', locale: $locale))
        ->not->toContain($code)
        ->and($message->getTo()[0]->getAddress())->toBe($user->email)
        ->and($message->getAttachments())->toHaveCount(1)
        ->and($record->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $record->code_hash))->toBeTrue()
        ->and($record->expires_at->diffInSeconds($record->created_at, true))->toBe(300.0)
        ->and(app()->getLocale())->toBe($previousLocale)
        ->and(EmailTwoFactor::verify($user->uuid, $code, 'different-purpose'))->toBeFalse()
        ->and(EmailTwoFactor::verify($user->uuid, $code, $purpose))->toBeTrue()
        ->and(EmailTwoFactor::verify($user->uuid, $code, $purpose))->toBeFalse();
})->with(['en', 'vi']);

it('explains the OTP purpose without exposing internal challenge identifiers', function (string $purpose, string $context): void {
    $mail = (new TwoFactorCodeMail('012345', 5, $purpose))->locale('vi');

    $mail->assertSeeInHtml(trans("notifications.two_factor.intros.$context", locale: 'vi'));
    $mail->assertSeeInText('012345');
    expect($mail->render())->not->toContain($purpose);
})->with([
    ['login', 'login'],
    ['two_factor_setup.private-setup-id', 'setup'],
    ['account_security.current', 'security'],
    ['sensitive_action.private-binding', 'security'],
    ['private-unknown-purpose', 'verification'],
]);

it('removes an undelivered OTP and restores the locale when transport fails', function (): void {
    $user = User::factory()->create();
    $user->profile->update(['locale' => 'vi']);
    app()->setLocale('en');
    Mail::mailer()->setSymfonyTransport(new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Synthetic mail transport failure.');
        }

        public function __toString(): string
        {
            return 'test-failing-transport';
        }
    });

    try {
        EmailTwoFactor::send($user->uuid, $user->email, 'delivery_failure');
        $this->fail('The failing transport must reject delivery.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['message'])->toBe([trans('auth.errors.email_code_send_failed', locale: 'en')]);
    }

    expect(TwoFactorCode::query()->count())->toBe(0)
        ->and(RateLimiter::attempts("2fa:send:email:delivery_failure:$user->uuid"))->toBe(0)
        ->and(app()->getLocale())->toBe('en');
});
