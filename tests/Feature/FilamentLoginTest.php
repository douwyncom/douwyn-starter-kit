<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Filament\Pages\Auth\Login;
use App\Models\Permission;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\Auth\AuthSignature;
use App\Support\TwoFactor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('panel.access', 'web');
});

function createFilamentLoginUser(TwoFactorMethod $method, ?string $secret = null): User
{
    $user = User::factory()->create([
        'email' => "admin-$method->value@example.com",
        'two_factor_method' => $method,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $user->givePermissionTo('panel.access');

    return $user;
}

it('issues and resends an email OTP from the Filament login page', function (): void {
    $user = createFilamentLoginUser(TwoFactorMethod::EMAIL);

    $component = Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ], 'credentialsForm')
        ->call('submitCredentials')
        ->assertHasNoErrors()
        ->assertSet('step', 'otp')
        ->assertSet('otpChannel', TwoFactorMethod::EMAIL->value)
        ->assertSet('canResend', true)
        ->assertSet('maskedDestination', 'ad*********@example.com');

    $firstCode = TwoFactorCode::query()->sole();

    expect($firstCode->channel)->toBe(TwoFactorMethod::EMAIL->value)
        ->and($firstCode->purpose)->toBe('login')
        ->and($firstCode->sent_to)->toBe($user->email)
        ->and($firstCode->consumed_at)->toBeNull();

    $this->travel(21)->seconds();

    $component
        ->call('resendOtp')
        ->assertHasNoErrors()
        ->assertSet('canResend', true);

    expect(TwoFactorCode::query()->count())->toBe(2)
        ->and($firstCode->refresh()->consumed_at)->not->toBeNull()
        ->and(TwoFactorCode::query()->whereNull('consumed_at')->sole()->sent_to)->toBe($user->email);
});

it('completes the Filament app TOTP login without creating a resendable code', function (): void {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = createFilamentLoginUser(TwoFactorMethod::APP, $secret);

    $component = Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ], 'credentialsForm')
        ->call('submitCredentials')
        ->assertHasNoErrors()
        ->assertSet('step', 'otp')
        ->assertSet('otpChannel', TwoFactorMethod::APP->value)
        ->assertSet('canResend', false)
        ->assertSet('maskedDestination', null);

    expect(TwoFactorCode::query()->count())->toBe(0);

    $component
        ->fillForm([
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ], 'otpForm')
        ->call('submitOtp')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);

    expect($user->refresh()->two_factor_last_used_timestamp)->toBeGreaterThan(0);
});

it('does not resend an unsupported pending OTP channel', function (): void {
    $user = createFilamentLoginUser(TwoFactorMethod::EMAIL);

    $this->withSession([
        'pending_user_uuid' => $user->uuid,
        'pending_otp_channel' => 'unsupported',
        'pending_auth_signature' => app(AuthSignature::class)->for($user),
        'pending_started_at' => now()->timestamp,
    ]);

    Livewire::test(Login::class)
        ->assertSet('step', 'credentials')
        ->assertSet('otpChannel', null)
        ->assertSet('canResend', false)
        ->assertSet('maskedDestination', null)
        ->call('resendOtp')
        ->assertHasNoErrors();

    expect(TwoFactorCode::query()->count())->toBe(0);
});

it('shows a localized and non-technical error when an email OTP cannot be sent', function (): void {
    $user = createFilamentLoginUser(TwoFactorMethod::EMAIL);
    $user->profile->update(['locale' => 'vi']);

    $rateLimitKey = "2fa:send:email:login:$user->uuid";

    foreach (range(1, 3) as $_) {
        RateLimiter::hit($rateLimitKey, 300);
    }

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ], 'credentialsForm')
        ->call('submitCredentials')
        ->assertHasErrors([
            'data.credentials.email' => fn (array $rules, array $messages): bool => str_starts_with(
                $messages[0] ?? '',
                'Có quá nhiều yêu cầu. Vui lòng thử lại sau',
            ),
        ]);
});

it('requires fresh credentials when authentication changes during a pending Filament login', function (string $change): void {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = createFilamentLoginUser(TwoFactorMethod::APP, $secret);

    $component = Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ], 'credentialsForm')
        ->call('submitCredentials')
        ->assertHasNoErrors()
        ->assertSet('step', 'otp');

    $user->forceFill(match ($change) {
        'password' => ['password' => 'ChangedPassword123!'],
        'two_factor_disabled' => ['two_factor_method' => TwoFactorMethod::NONE],
        'two_factor_secret' => ['two_factor_secret' => $secret = TwoFactor::google2fa()->generateSecretKey()],
    })->save();

    $component
        ->fillForm(['otp' => TwoFactor::google2fa()->getCurrentOtp($secret)], 'otpForm')
        ->call('submitOtp')
        ->assertHasErrors(['data.otp.otp'])
        ->assertSet('step', 'credentials');

    $this->assertGuest();

    expect(session('pending_user_uuid'))->toBeNull()
        ->and($user->refresh()->two_factor_last_used_timestamp)->toBeNull();
})->with(['password', 'two_factor_disabled', 'two_factor_secret']);
