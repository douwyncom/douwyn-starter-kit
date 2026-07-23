<?php

namespace App\Filament\Pages\Auth;

use App\Enums\AuthCredentialType;
use App\Enums\TwoFactorMethod;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Rules\PasswordWithinHashLimit;
use App\Services\Security\SecurityTelemetry;
use App\Support\EmailTwoFactor;
use App\Support\TwoFactor;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
use Throwable;

/**
 * @property string $view
 * @property mixed $credentialsForm
 * @property mixed $otpForm
 */
class Login extends SimplePage
{
    use WithRateLimiting;

    private const string DUMMY_PASSWORD_HASH = '$2y$12$E570YWU.j7rrZqVjLo/Kbe2F59RVGxZ1a3F5ZtBfWldnzk21KrQNa';

    public function getTitle(): string|Htmlable
    {
        return __('auth.login_page_title');
    }

    protected string $view = 'filament.pages.auth.login';

    public string $step = 'credentials';

    /** @var array<string,mixed> */
    public array $data = [];

    #[Locked]
    public ?string $pendingUserUuid = null;

    /** email|app|telegram... */
    #[Locked]
    public ?string $otpChannel = null;

    public ?string $maskedDestination = null;

    public bool $canResend = false;

    public string $otpMode = 'otp'; // otp|recovery

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->pendingUserUuid = session('pending_user_uuid');
        $this->otpChannel = session('pending_otp_channel');
        $this->step = $this->getPendingUser() ? 'otp' : 'credentials';

        $this->credentialsForm->fill([
            'email' => '',
            'password' => '',
        ]);

        $this->otpForm->fill([
            'otp' => '',
        ]);

        $this->syncOtpUiMeta();
    }

    // =========================
    // FORMS
    // =========================

    public function credentialsForm(Schema $form): Schema
    {
        return $form
            ->statePath('data.credentials')
            ->schema([
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->autocomplete('email')
                    ->autofocus(),

                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(72)
                    ->rule(new PasswordWithinHashLimit)
                    ->autocomplete('password'),
            ]);
    }

    public function otpForm(Schema $form): Schema
    {
        return $form
            ->statePath('data.otp')
            ->schema([
                TextInput::make('otp')
                    ->label(fn () => $this->otpMode === 'recovery' ? __('Recovery code') : __('Verification code'))
                    ->helperText(fn () => $this->otpMode === 'recovery'
                        ? __('Enter one of your recovery codes.')
                        : __('Enter the :digits-digit code.', ['digits' => 6]))
                    ->required()
                    ->autocomplete('one-time-code'),
            ]);
    }

    // =========================
    // HANDLERS
    // =========================

    public function submitCredentials(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()
                ->title(__('Too many attempts'))
                ->body(__('Try again in :s seconds.', ['s' => $e->secondsUntilAvailable]))
                ->danger()
                ->send();

            return;
        }

        $state = (array) $this->credentialsForm->getState();

        $email = Str::lower(trim((string) ($state['email'] ?? '')));
        $password = (string) ($state['password'] ?? '');

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $email)
            ->first();

        $passwordIsValid = Hash::check($password, (string) ($user?->password ?? self::DUMMY_PASSWORD_HASH));

        if (! $user || ! $passwordIsValid) {
            app(SecurityTelemetry::class)->loginFailed(
                $user,
                request(),
                'filament',
                'invalid_credentials',
                $email,
            );

            throw ValidationException::withMessages([
                'data.credentials.email' => __('Invalid credentials.'),
            ]);
        }

        if ($user->is_inactive || ! $this->canAccessCurrentPanel($user)) {
            app(SecurityTelemetry::class)->loginFailed(
                $user,
                request(),
                'filament',
                'panel_access_denied',
                $email,
            );

            throw ValidationException::withMessages([
                'data.credentials.email' => __('You are not authorized to access this panel.'),
            ]);
        }

        if (config('hashing.rehash_on_login', true) && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        $channel = $this->resolveUserOtpChannel($user); // null|email|app|telegram...

        if (blank($channel)) {
            Filament::auth()->login($user, false);
            session()->regenerate();
            $this->clearPending();
            app(SecurityTelemetry::class)->loginSucceeded(
                $user,
                request(),
                'filament',
                AuthCredentialType::SESSION,
            );
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->pendingUserUuid = $user->uuid;
        $this->otpChannel = $channel;

        session([
            'pending_user_uuid' => $this->pendingUserUuid,
            'pending_otp_channel' => $this->otpChannel,
            'pending_started_at' => now()->timestamp,
        ]);

        if (in_array($channel, ['email', 'telegram'], true)) {
            $this->sendOtp($user, $channel);
        }

        app(SecurityTelemetry::class)->twoFactorChallengeIssued(
            $user,
            request(),
            'filament',
            AuthCredentialType::SESSION,
            $channel,
        );

        $this->step = 'otp';
        $this->otpForm->fill(['otp' => '']);

        $this->syncOtpUiMeta();

        Notification::make()
            ->title(__('Verification required'))
            ->body(__('Please enter the verification code.'))
            ->info()
            ->send();
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function submitOtp(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()
                ->title(__('Too many attempts'))
                ->body(__('Try again in :s seconds.', ['s' => $e->secondsUntilAvailable]))
                ->danger()
                ->send();

            return;
        }

        $user = $this->getPendingUser();

        if (! $user) {
            app(SecurityTelemetry::class)->twoFactorVerificationFailed(
                null,
                request(),
                'filament',
                AuthCredentialType::SESSION,
                'challenge_invalid_or_expired',
            );
            $this->backToCredentials();
            throw ValidationException::withMessages([
                'data.otp.otp' => __('Session expired. Please sign in again.'),
            ]);
        }

        if ($user->is_inactive || ! $this->canAccessCurrentPanel($user)) {
            app(SecurityTelemetry::class)->twoFactorVerificationFailed(
                $user,
                request(),
                'filament',
                AuthCredentialType::SESSION,
                'panel_access_denied',
                $this->otpChannel,
            );
            $this->backToCredentials();

            throw ValidationException::withMessages([
                'data.otp.otp' => __('You are not authorized to access this panel.'),
            ]);
        }

        $mode = $this->otpMode;

        $state = (array) $this->otpForm->getState();
        $otp = preg_replace('/\s+/', '', (string) ($state['otp'] ?? ''));

        if ($mode !== 'recovery') {
            if (strlen($otp) !== 6) {
                app(SecurityTelemetry::class)->twoFactorVerificationFailed(
                    $user,
                    request(),
                    'filament',
                    AuthCredentialType::SESSION,
                    'invalid_code_format',
                    $this->otpChannel,
                );

                throw ValidationException::withMessages([
                    'data.otp.otp' => __('Enter the :digits-digit code.', ['digits' => 6]),
                ]);
            }
        }

        $channel = ($this->otpChannel ?? '');

        $ok = match ($mode) {
            'recovery' => $this->verifyRecoveryCode($user, $otp),
            default => match ($channel) {
                'email' => EmailTwoFactor::verify($user->uuid, $otp, purpose: 'login'),
                'telegram' => $this->verifyDbOtp($user->uuid, 'telegram', $otp, purpose: 'login'),
                'app' => $this->verifyTotp($user, $otp),
                default => false,
            },
        };

        if (! $ok) {
            app(SecurityTelemetry::class)->twoFactorVerificationFailed(
                $user,
                request(),
                'filament',
                AuthCredentialType::SESSION,
                'invalid_code',
                $channel,
            );

            throw ValidationException::withMessages([
                'data.otp.otp' => __('Invalid or expired code.'),
            ]);
        }

        Filament::auth()->login($user, false);
        session()->regenerate();
        $this->clearPending();
        app(SecurityTelemetry::class)->twoFactorVerified(
            $user,
            request(),
            'filament',
            AuthCredentialType::SESSION,
            $channel,
            $mode === 'recovery',
        );
        app(SecurityTelemetry::class)->loginSucceeded(
            $user,
            request(),
            'filament',
            AuthCredentialType::SESSION,
            true,
        );

        redirect()->intended(Filament::getUrl());
    }

    private function verifyRecoveryCode(User $user, string $input): bool
    {
        return TwoFactor::consumeRecoveryCode($user, $input);
    }

    private function canAccessCurrentPanel(User $user): bool
    {
        $panel = Filament::getCurrentPanel();

        return $panel !== null && $user->canAccessPanel($panel);
    }

    public function resendOtp(): void
    {
        try {
            $this->rateLimit(3); // resend throttle
        } catch (TooManyRequestsException $e) {
            Notification::make()
                ->title(__('Too many requests'))
                ->body(__('Try again in :s seconds.', ['s' => $e->secondsUntilAvailable]))
                ->warning()
                ->send();

            return;
        }

        $user = $this->getPendingUser();
        if (! $user) {
            $this->backToCredentials();

            return;
        }

        if (! in_array($this->otpChannel, ['email', 'telegram'], true)) {
            Notification::make()->title(__('Resend not available'))->warning()->send();

            return;
        }

        $this->sendOtp($user, $this->otpChannel);

        $this->syncOtpUiMeta();

        Notification::make()->title(__('Code sent'))->success()->send();
    }

    public function backToCredentials(): void
    {
        $this->clearPending();

        $this->step = 'credentials';
        $this->otpForm->fill(['otp' => '']);

        $this->syncOtpUiMeta();
    }

    // =========================
    // HELPERS
    // =========================

    private function resolveUserOtpChannel(User $user): ?string
    {
        if ($user->hasEnabledTwoFactor()) {
            $method = $user->two_factor_method instanceof TwoFactorMethod
                ? $user->two_factor_method->value
                : (string) $user->two_factor_method;

            return $method === 'none' ? null : $method;
        }

        return null;
    }

    private function sendOtp(User $user, string $channel): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()
                ->title(__('Too many attempts'))
                ->body(__('Try again in :s seconds.', ['s' => $e->secondsUntilAvailable]))
                ->danger()
                ->send();

            return;
        }

        if ($channel === 'email') {
            try {
                EmailTwoFactor::send($user->uuid, $user->email, purpose: 'login');
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'data.otp.otp' => $e->getMessage(),
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'data.otp.otp' => __('This channel is not implemented yet.'),
        ]);
    }

    private function verifyDbOtp(string $userUuid, string $channel, string $otp, string $purpose): bool
    {
        return DB::transaction(function () use ($userUuid, $channel, $otp, $purpose): bool {
            $record = TwoFactorCode::query()
                ->where('user_uuid', $userUuid)
                ->where('channel', $channel)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $record || $record->expires_at->isPast() || ! Hash::check($otp, $record->code_hash)) {
                return false;
            }

            $record->update(['consumed_at' => now()]);

            return true;
        });
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws SecretKeyTooShortException
     * @throws InvalidCharactersException
     */
    private function verifyTotp(User $user, string $otp): bool
    {
        $secret = (string) ($user->two_factor_secret ?? $user->app_authentication_secret ?? '');

        if ($secret === '') {
            return false;
        }

        return TwoFactor::verifyAndConsumeTotp($user, $otp);
    }

    private function getPendingUser(): ?User
    {
        $pendingUserUuid = session('pending_user_uuid');
        $pendingStartedAt = (int) session('pending_started_at', 0);

        if (blank($pendingUserUuid) || $pendingStartedAt < now()->subMinutes(10)->timestamp) {
            $this->clearPending();

            return null;
        }

        return User::query()->where('uuid', $pendingUserUuid)->first();
    }

    private function clearPending(): void
    {
        $this->pendingUserUuid = null;
        $this->otpChannel = null;

        session()->forget('pending_user_uuid');
        session()->forget('pending_otp_channel');
        session()->forget('pending_remember');
        session()->forget('pending_started_at');
    }

    private function syncOtpUiMeta(): void
    {
        $this->canResend = in_array($this->otpChannel, ['email', 'telegram'], true);

        $this->maskedDestination = null;

        $user = $this->getPendingUser();
        if (! $user) {
            return;
        }

        if ($this->otpChannel === 'email') {
            $this->maskedDestination = $this->maskEmail($user->email);
        }
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        [$name, $domain] = $parts;
        $nameMasked = mb_substr($name, 0, 2).str_repeat('*', max(mb_strlen($name) - 2, 1));

        return $nameMasked.'@'.$domain;
    }
}
