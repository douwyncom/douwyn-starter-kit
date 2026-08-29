<?php

namespace App\Filament\Clusters\Account\Pages;

use App\Data\Auth\IssuedTwoFactorSetup;
use App\Enums\TwoFactorMethod;
use App\Filament\Clusters\Account\AccountCluster;
use App\Models\TwoFactorSetup;
use App\Models\User;
use App\Rules\PasswordWithinHashLimit;
use App\Services\Auth\TwoFactorSetupService;
use App\Services\Security\SecurityTelemetry;
use App\Support\TwoFactor;
use BackedEnum;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * @property mixed $form
 */
class Security extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.clusters.account.pages.security';

    protected static ?string $cluster = AccountCluster::class;

    protected static ?string $title = 'Security';

    protected ?string $subheading = 'Configure two-factor authentication (2FA).';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 1;

    public array $data = [];

    public ?string $qrSvg = null;

    public ?string $pendingAppSecret = null;

    public ?string $pendingSetupMethod = null;

    public ?string $pendingSetupExpiresAt = null;

    /** @var array<int, string> */
    public array $newRecoveryCodes = [];

    public static function getNavigationLabel(): string
    {
        return __('pages/account.security.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('pages/account.security.subheading');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->fillFormFromUser();
        $this->restorePendingSetup();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Grid::make()
                    ->columns(['default' => 1, 'lg' => 3])
                    ->schema([
                        Grid::make()
                            ->columns(['default' => 1, 'lg' => 1])
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->schema([
                                SchemaView::make('filament.clusters.account.partials.two-factor-status')
                                    ->viewData(fn () => [
                                        'label' => $this->getTwoFactorStatusLabelProperty(),
                                        'color' => $this->getTwoFactorStatusColorProperty(),
                                    ]),

                                Section::make(__('pages/account.security.two_factor_title'))
                                    ->description(__('pages/account.security.two_factor_helper'))
                                    ->schema([
                                        TextInput::make('current_password')
                                            ->label(__('pages/account.password.current_password'))
                                            ->helperText(__('Confirm your password before changing or regenerating two-factor settings.'))
                                            ->password()
                                            ->revealable()
                                            ->maxLength(72)
                                            ->rule(new PasswordWithinHashLimit)
                                            ->autocomplete('current-password'),

                                        TextInput::make('current_factor_code')
                                            ->label(__('Current 2FA or recovery code'))
                                            ->helperText(fn (): string => $this->isEmailEnabled()
                                                ? __('Request an email code below, then enter it here. A recovery code is also accepted.')
                                                : __('Enter the current authenticator code or a recovery code.'))
                                            ->password()
                                            ->revealable()
                                            ->maxLength(64)
                                            ->autocomplete('one-time-code')
                                            ->visible(fn (): bool => $this->isEnabled()),

                                        Actions::make([
                                            Action::make('sendCurrentFactorEmailCode')
                                                ->label(__('Send current-factor email code'))
                                                ->icon(Heroicon::OutlinedEnvelope)
                                                ->visible(fn (): bool => $this->isEmailEnabled())
                                                ->action('sendCurrentFactorEmailCode'),
                                        ])->visible(fn (): bool => $this->isEmailEnabled()),

                                        Radio::make('two_factor_method')
                                            ->label('2FA method')
                                            ->options($this->methodOptions())
                                            ->descriptions($this->methodDescriptions())
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function ($state): void {
                                                if ($state === TwoFactorMethod::APP->value && $this->pendingAppSecret) {
                                                    $this->renderQrFor($this->pendingAppSecret, $this->user()->email);

                                                    return;
                                                }

                                                $this->qrSvg = null;
                                            }),

                                        Actions::make([
                                            Action::make('saveMethod')
                                                ->label(__('pages/account.save_change'))
                                                ->icon(Heroicon::OutlinedCheckCircle)
                                                ->action('saveMethod'),
                                        ]),
                                    ]),

                                Section::make(__('pages/account.security.authenticator_title'))
                                    ->description(__('pages/account.security.authenticator_helper'))
                                    ->visible(fn (): bool => ($this->data['two_factor_method'] ?? TwoFactorMethod::NONE->value) === TwoFactorMethod::APP->value)
                                    ->schema([
                                        SchemaView::make('filament.clusters.account.partials.qr')
                                            ->viewData(fn () => [
                                                'qrSvg' => $this->qrSvg,
                                                'secret' => $this->pendingAppSecret,
                                                'isEnabled' => $this->isAppEnabled(),
                                                'isPending' => $this->isAppPending(),
                                                'expiresAt' => $this->pendingSetupExpiresAt,
                                            ]),

                                        TextInput::make('otp_code')
                                            ->label(__('pages/account.security.authenticator_code'))
                                            ->helperText(__('pages/account.security.authenticator_code_helper'))
                                            ->numeric()
                                            ->minLength(6)
                                            ->maxLength(6)
                                            ->visible(fn (): bool => $this->isAppPending())
                                            ->live(onBlur: true),

                                        Actions::make([
                                            Action::make('regenerateSecret')
                                                ->label(__('pages/account.security.regenerate_secret'))
                                                ->color('gray')
                                                ->icon(Heroicon::OutlinedArrowPath)
                                                ->action('regenerateAppSecret'),

                                            Action::make('confirmApp')
                                                ->label(__('pages/account.security.confirm_enabled'))
                                                ->visible(fn (): bool => $this->isAppPending())
                                                ->icon(Heroicon::OutlinedShieldCheck)
                                                ->action('confirmApp2fa'),
                                        ])->alignEnd(),
                                    ]),

                                Section::make(__('pages/account.security.email_two_factor_title'))
                                    ->description(__('pages/account.security.email_two_factor_helper'))
                                    ->visible(fn (): bool => ($this->data['two_factor_method'] ?? TwoFactorMethod::NONE->value) === TwoFactorMethod::EMAIL->value
                                        && (! $this->isEmailEnabled() || $this->isEmailPending()))
                                    ->schema([
                                        Actions::make([
                                            Action::make('sendEmailCode')
                                                ->label(fn (): string => $this->isEmailPending()
                                                    ? __('Resend verification code')
                                                    : __('pages/account.security.email_send_code'))
                                                ->icon(Heroicon::OutlinedEnvelope)
                                                ->action('sendEmailCode'),
                                        ]),

                                        TextInput::make('email_code')
                                            ->label(__('pages/account.security.email_code_title'))
                                            ->helperText(__('pages/account.security.email_code_helper'))
                                            ->numeric()
                                            ->minLength(6)
                                            ->maxLength(6)
                                            ->visible(fn (): bool => $this->isEmailPending())
                                            ->live(onBlur: true),

                                        Actions::make([
                                            Action::make('verifyEmail')
                                                ->label(__('pages/account.security.verify_enabled'))
                                                ->visible(fn (): bool => $this->isEmailPending())
                                                ->icon(Heroicon::OutlinedCheckCircle)
                                                ->action('verifyEmailCode'),
                                        ])->alignEnd(),
                                    ]),
                            ]),

                        Section::make(__('pages/account.security.recovery_code_title'))
                            ->description(__('pages/account.security.recovery_code_helper'))
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->schema([
                                SchemaView::make('filament.clusters.account.partials.recovery-codes')
                                    ->viewData(fn () => [
                                        'method' => $this->user()->two_factor_method?->value ?? TwoFactorMethod::NONE->value,
                                        'enabled' => $this->isEnabled(),
                                        'codes' => $this->newRecoveryCodes,
                                        'remaining' => count((array) ($this->user()->two_factor_recovery_codes ?? [])),
                                    ]),

                                Actions::make([
                                    Action::make('regenerateRecoveryCodes')
                                        ->label(__('pages/account.security.regenerate_code'))
                                        ->color('gray')
                                        ->icon(Heroicon::OutlinedArrowPath)
                                        ->requiresConfirmation()
                                        ->visible(fn (): bool => $this->isEnabled())
                                        ->action('regenerateRecoveryCodes'),
                                ])->alignEnd(),
                            ]),
                    ]),
            ]);
    }

    public function saveMethod(): void
    {
        $user = $this->user()->fresh();
        $method = TwoFactorMethod::from($this->data['two_factor_method'] ?? TwoFactorMethod::NONE->value);

        if ($method === ($user->two_factor_method ?? TwoFactorMethod::NONE) && $user->hasEnabledTwoFactor()) {
            $this->clearStepUpFields();
            $this->notifyNoChanges();

            return;
        }

        if ($method === TwoFactorMethod::NONE
            && ($user->two_factor_method ?? TwoFactorMethod::NONE) === TwoFactorMethod::NONE
            && blank($user->two_factor_secret)) {
            $this->clearStepUpFields();
            $this->notifyNoChanges();

            return;
        }

        [$otp, $recoveryCode] = $this->currentFactorCredentials();
        $password = (string) ($this->data['current_password'] ?? '');

        if ($method === TwoFactorMethod::NONE) {
            $this->callSecurityService(fn () => $this->security()->disable(
                $user,
                $password,
                $otp,
                $recoveryCode,
                request(),
            ));

            $this->clearPendingSetup();
            $this->fillFormFromUser();

            Notification::make()
                ->title(__('Saved'))
                ->body(__('Two-factor authentication disabled.'))
                ->success()
                ->send();

            return;
        }

        $setup = $method === TwoFactorMethod::APP
            ? $this->callSecurityService(fn () => $this->security()->startApp(
                $user,
                $password,
                $otp,
                $recoveryCode,
            ))
            : $this->callSecurityService(fn () => $this->security()->startEmail(
                $user,
                $password,
                $otp,
                $recoveryCode,
            ), [
                'email' => 'data.email_code',
                'message' => 'data.email_code',
            ]);

        $this->rememberPendingSetup($setup);
        $this->clearStepUpFields();

        Notification::make()
            ->title(__('Saved'))
            ->body($method === TwoFactorMethod::APP
                ? __('Scan the new authenticator secret and confirm its code.')
                : __('A verification code has been sent to your email address.'))
            ->success()
            ->send();
    }

    public function regenerateAppSecret(): void
    {
        $user = $this->user()->fresh();
        [$otp, $recoveryCode] = $this->currentFactorCredentials();

        $setup = $this->callSecurityService(fn () => $this->security()->startApp(
            $user,
            (string) ($this->data['current_password'] ?? ''),
            $otp,
            $recoveryCode,
        ));

        $this->rememberPendingSetup($setup);
        $this->clearStepUpFields();
        $this->data['otp_code'] = '';

        Notification::make()
            ->title(__('pages/account.security.regenerated'))
            ->body(__('The active authenticator remains valid until this new secret is confirmed.'))
            ->info()
            ->send();
    }

    public function confirmApp2fa(): void
    {
        $token = $this->pendingSetupToken();

        if (! $token || ! $this->isAppPending()) {
            throw ValidationException::withMessages([
                'data.otp_code' => [__('The two-factor setup is invalid or expired.')],
            ]);
        }

        $result = $this->callSecurityService(fn () => $this->security()->confirmApp(
            $this->user(),
            $token,
            (string) ($this->data['otp_code'] ?? ''),
            request(),
        ), [
            'otp' => 'data.otp_code',
            'setup_token' => 'data.otp_code',
        ]);

        $this->newRecoveryCodes = $result['recovery_codes'];
        $this->clearPendingSetup();
        $this->fillFormFromUser();

        Notification::make()
            ->title(__('pages/account.enabled'))
            ->body(__('pages/account.security.app_authenticator_confirm'))
            ->success()
            ->send();
    }

    public function sendCurrentFactorEmailCode(): void
    {
        $this->callSecurityService(fn () => $this->security()->sendCurrentEmailCode(
            $this->user(),
            (string) ($this->data['current_password'] ?? ''),
        ), [
            'email' => 'data.current_factor_code',
            'message' => 'data.current_factor_code',
            'two_factor' => 'data.current_factor_code',
        ]);

        Notification::make()
            ->title(__('pages/account.sent'))
            ->body(__('pages/account.security.sent_body'))
            ->success()
            ->send();
    }

    public function sendEmailCode(): void
    {
        $token = $this->pendingSetupToken();

        if ($token && $this->isEmailPending()) {
            $this->callSecurityService(fn () => $this->security()->resendEmail(
                $this->user(),
                $token,
            ), [
                'email' => 'data.email_code',
                'message' => 'data.email_code',
                'setup_token' => 'data.email_code',
            ]);
        } else {
            $user = $this->user()->fresh();
            [$otp, $recoveryCode] = $this->currentFactorCredentials();
            $setup = $this->callSecurityService(fn () => $this->security()->startEmail(
                $user,
                (string) ($this->data['current_password'] ?? ''),
                $otp,
                $recoveryCode,
            ), [
                'email' => 'data.email_code',
                'message' => 'data.email_code',
            ]);

            $this->rememberPendingSetup($setup);
            $this->clearStepUpFields();
        }

        Notification::make()
            ->title(__('pages/account.sent'))
            ->body(__('pages/account.security.sent_body'))
            ->success()
            ->send();
    }

    public function verifyEmailCode(): void
    {
        $token = $this->pendingSetupToken();

        if (! $token || ! $this->isEmailPending()) {
            throw ValidationException::withMessages([
                'data.email_code' => [__('The two-factor setup is invalid or expired.')],
            ]);
        }

        $result = $this->callSecurityService(fn () => $this->security()->confirmEmail(
            $this->user(),
            $token,
            (string) ($this->data['email_code'] ?? ''),
            request(),
        ), [
            'otp' => 'data.email_code',
            'setup_token' => 'data.email_code',
        ]);

        $this->newRecoveryCodes = $result['recovery_codes'];
        $this->clearPendingSetup();
        $this->fillFormFromUser();

        Notification::make()
            ->title(__('pages/account.enabled'))
            ->body(__('pages/account.security.email_enabled'))
            ->success()
            ->send();
    }

    public function regenerateRecoveryCodes(): void
    {
        [$otp, $recoveryCode] = $this->currentFactorCredentials();
        $result = $this->callSecurityService(fn () => $this->security()->regenerateRecoveryCodes(
            $this->user(),
            (string) ($this->data['current_password'] ?? ''),
            $otp,
            $recoveryCode,
            request(),
        ));

        $this->newRecoveryCodes = $result['recovery_codes'];
        $this->user()->refresh();
        $this->clearStepUpFields();

        Notification::make()
            ->title(__('pages/account.updated'))
            ->body(__('pages/account.security.recovery_code_regenerate'))
            ->success()
            ->send();
    }

    private function methodOptions(): array
    {
        return collect(TwoFactorMethod::cases())
            ->mapWithKeys(fn (TwoFactorMethod $method) => [$method->value => $method->getLabel()])
            ->all();
    }

    private function methodDescriptions(): array
    {
        return [
            TwoFactorMethod::NONE->value => __('pages/account.security.none_description'),
            TwoFactorMethod::EMAIL->value => __('pages/account.security.email_description'),
            TwoFactorMethod::APP->value => __('pages/account.security.app_description'),
        ];
    }

    private function rememberPendingSetup(IssuedTwoFactorSetup $issued): void
    {
        $this->user()->refresh();
        session()->put(
            $this->pendingSetupSessionKey(),
            Crypt::encryptString($issued->plainTextToken),
        );
        $this->pendingSetupMethod = $issued->setup->method->value;
        $this->pendingSetupExpiresAt = $issued->setup->expires_at->toIso8601String();
        $this->pendingAppSecret = $issued->secret;
        $this->data['two_factor_method'] = $issued->setup->method->value;
        $this->newRecoveryCodes = [];

        if ($issued->secret) {
            $this->renderQrFor($issued->secret, $this->user()->email);
        } else {
            $this->qrSvg = null;
        }
    }

    private function restorePendingSetup(): void
    {
        $token = $this->pendingSetupToken();

        if (! $token) {
            return;
        }

        $setup = TwoFactorSetup::query()
            ->where('user_uuid', $this->user()->uuid)
            ->where('token_hash', TwoFactorSetup::tokenHash($token))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $setup) {
            $this->clearPendingSetup();

            return;
        }

        $this->pendingSetupMethod = $setup->method->value;
        $this->pendingSetupExpiresAt = $setup->expires_at->toIso8601String();
        $this->pendingAppSecret = $setup->method === TwoFactorMethod::APP ? $setup->secret : null;
        $this->data['two_factor_method'] = $setup->method->value;

        if ($this->pendingAppSecret) {
            $this->renderQrFor($this->pendingAppSecret, $this->user()->email);
        }
    }

    private function clearPendingSetup(): void
    {
        session()->forget($this->pendingSetupSessionKey());
        $this->pendingSetupMethod = null;
        $this->pendingSetupExpiresAt = null;
        $this->pendingAppSecret = null;
        $this->qrSvg = null;
    }

    private function pendingSetupToken(): ?string
    {
        $encryptedToken = session($this->pendingSetupSessionKey());

        if (! is_string($encryptedToken) || $encryptedToken === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedToken);
        } catch (DecryptException) {
            session()->forget($this->pendingSetupSessionKey());

            return null;
        }
    }

    private function pendingSetupSessionKey(): string
    {
        return "account_security.pending_setup.{$this->user()->uuid}";
    }

    private function fillFormFromUser(): void
    {
        $user = $this->user();
        $user->refresh();

        $this->form->fill([
            'two_factor_method' => ($user->two_factor_method ?? TwoFactorMethod::NONE)->value,
            'current_password' => '',
            'current_factor_code' => '',
            'otp_code' => '',
            'email_code' => '',
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function currentFactorCredentials(): array
    {
        $value = trim((string) ($this->data['current_factor_code'] ?? ''));

        if (preg_match('/^\d{6}$/D', $value)) {
            return [$value, null];
        }

        return [null, $value !== '' ? $value : null];
    }

    private function clearStepUpFields(): void
    {
        $this->data['current_password'] = '';
        $this->data['current_factor_code'] = '';
    }

    private function renderQrFor(string $secret, string $email): void
    {
        $qrUrl = TwoFactor::google2fa()->getQRCodeUrl(config('app.name'), $email, $secret);
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        $this->qrSvg = new Writer($renderer)->writeString($qrUrl);
    }

    private function callSecurityService(Closure $callback, array $fieldMap = []): mixed
    {
        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');

        try {
            return $callback();
        } catch (ValidationException $exception) {
            $defaultMap = [
                'current_password' => 'data.current_password',
                'otp' => 'data.current_factor_code',
                'recovery_code' => 'data.current_factor_code',
                'two_factor' => 'data.two_factor_method',
            ];
            $messages = [];

            foreach ($exception->errors() as $field => $errors) {
                $messages[$fieldMap[$field] ?? $defaultMap[$field] ?? "data.$field"] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    private function notifyNoChanges(): void
    {
        Notification::make()
            ->title(__('Saved'))
            ->body(__('No two-factor changes were required.'))
            ->success()
            ->send();
    }

    private function security(): TwoFactorSetupService
    {
        return app(TwoFactorSetupService::class);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function getTwoFactorStatusLabelProperty(): string
    {
        $user = $this->user();

        if ($this->hasPendingSetup()
            || (($user->two_factor_method ?? TwoFactorMethod::NONE) !== TwoFactorMethod::NONE && ! $this->isEnabled())) {
            return __('pages/account.security.pending_confirm');
        }

        return match ($user->two_factor_method ?? TwoFactorMethod::NONE) {
            TwoFactorMethod::EMAIL => __('pages/account.security.enabled_email'),
            TwoFactorMethod::APP => __('pages/account.security.enabled_app'),
            default => __('pages/account.security.disabled'),
        };
    }

    public function getTwoFactorStatusColorProperty(): string
    {
        if ($this->hasPendingSetup()) {
            return 'warning';
        }

        return $this->isEnabled() ? 'success' : 'gray';
    }

    private function hasPendingSetup(): bool
    {
        return in_array($this->pendingSetupMethod, [
            TwoFactorMethod::APP->value,
            TwoFactorMethod::EMAIL->value,
        ], true);
    }

    private function isEnabled(): bool
    {
        return $this->user()->hasEnabledTwoFactor();
    }

    private function isEmailEnabled(): bool
    {
        return $this->user()->hasEnabledTwoFactor(TwoFactorMethod::EMAIL);
    }

    private function isAppEnabled(): bool
    {
        return $this->user()->hasEnabledTwoFactor(TwoFactorMethod::APP);
    }

    private function isEmailPending(): bool
    {
        return $this->pendingSetupMethod === TwoFactorMethod::EMAIL->value;
    }

    private function isAppPending(): bool
    {
        return $this->pendingSetupMethod === TwoFactorMethod::APP->value
            && filled($this->pendingAppSecret);
    }
}
