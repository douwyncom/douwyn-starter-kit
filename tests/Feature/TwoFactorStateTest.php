<?php

use App\Enums\SecurityEvent;
use App\Enums\TwoFactorMethod;
use App\Filament\Clusters\Account\Pages\Security;
use App\Models\TwoFactorCode;
use App\Models\TwoFactorSetup;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('only treats a confirmed and enabled method as active two factor authentication', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_confirmed_at' => null,
        'two_factor_enabled_at' => null,
    ]);

    expect($user->hasEnabledTwoFactor())->toBeFalse();

    $user->forceFill([
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    expect($user->hasEnabledTwoFactor())->toBeTrue()
        ->and($user->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue()
        ->and($user->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeFalse();
});

it('falls back to the email when a user has no profile', function () {
    $user = User::factory()->make(['email' => 'missing-profile@example.com']);

    expect($user->getFilamentName())->toBe('missing-profile@example.com');
});

it('normalizes email addresses before persistence', function () {
    $user = User::factory()->create(['email' => '  Admin@Example.COM  ']);

    expect($user->email)->toBe('admin@example.com');
});

it('keeps the existing two factor method active until a replacement is confirmed', function () {
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('data.two_factor_method', TwoFactorMethod::APP->value)
        ->set('data.current_password', 'Secret123')
        ->set('data.current_factor_code', 'recovery123456')
        ->call('saveMethod')
        ->assertHasNoErrors();

    $user->refresh();
    $setup = TwoFactorSetup::query()->firstOrFail();

    expect($user->two_factor_method)->toBe(TwoFactorMethod::EMAIL)
        ->and($user->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($setup->method)->toBe(TwoFactorMethod::APP)
        ->and($setup->secret)->not->toBeEmpty();
});

it('requires the current password before disabling two factor authentication', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('data.two_factor_method', TwoFactorMethod::NONE->value)
        ->call('saveMethod')
        ->assertHasErrors(['data.current_password']);

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeTrue();
});

it('requires the current factor before disabling enabled two factor authentication', function () {
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('data.two_factor_method', TwoFactorMethod::NONE->value)
        ->set('data.current_password', 'Secret123')
        ->call('saveMethod')
        ->assertHasErrors(['data.current_factor_code']);

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeTrue();
});

it('stores regenerated recovery codes as hashes and reveals the raw values once', function () {
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->set('data.current_password', 'Secret123')
        ->set('data.current_factor_code', 'RECOVERY-123456')
        ->call('regenerateRecoveryCodes')
        ->assertHasNoErrors();

    $rawCodes = $component->get('newRecoveryCodes');
    $storedCodes = $user->fresh()->two_factor_recovery_codes;

    expect($rawCodes)->toHaveCount(10)
        ->and($storedCodes)->toHaveCount(10);

    foreach ($storedCodes as $storedCode) {
        expect($storedCode)->toStartWith('sha256:')
            ->not->toBeIn($rawCodes);
    }

    Livewire::test(Security::class)
        ->assertSet('newRecoveryCodes', []);
});

it('keeps an active authenticator valid while regenerating a replacement secret', function () {
    $oldSecret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $oldSecret,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->set('data.current_password', 'Secret123')
        ->set('data.current_factor_code', 'RECOVERY-123456')
        ->call('regenerateAppSecret')
        ->assertHasNoErrors()
        ->assertSet('pendingSetupMethod', TwoFactorMethod::APP->value);

    $user->refresh();
    $setup = TwoFactorSetup::query()->firstOrFail();

    expect($user->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue()
        ->and($user->two_factor_secret)->toBe($oldSecret)
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->two_factor_enabled_at)->not->toBeNull()
        ->and($setup->secret)->toBe($component->get('pendingAppSecret'))
        ->and($setup->secret)->not->toBe($oldSecret);
});

it('confirms a pending authenticator and reveals hashed recovery codes once', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->set('data.two_factor_method', TwoFactorMethod::APP->value)
        ->set('data.current_password', 'Secret123')
        ->call('saveMethod')
        ->assertHasNoErrors();

    $secret = $component->get('pendingAppSecret');
    $component
        ->set('data.otp_code', TwoFactor::google2fa()->getCurrentOtp($secret))
        ->call('confirmApp2fa')
        ->assertHasNoErrors()
        ->assertSet('pendingSetupMethod', null);

    $rawCodes = $component->get('newRecoveryCodes');
    $storedCodes = $user->fresh()->two_factor_recovery_codes;

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue()
        ->and($rawCodes)->toHaveCount(10)
        ->and($storedCodes)->toHaveCount(10)
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::SECURITY_CHANGED->value)
            ->where('subject_id', $user->uuid)
            ->where('properties->channel', 'filament')
            ->exists())->toBeTrue();

    foreach ($storedCodes as $storedCode) {
        expect($storedCode)->toStartWith('sha256:')
            ->not->toBeIn($rawCodes);
    }
});

it('keeps the app factor active until a pending email replacement is confirmed', function () {
    Mail::fake();

    $oldSecret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $oldSecret,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $this->actingAs($user);

    $component = Livewire::test(Security::class)
        ->set('data.two_factor_method', TwoFactorMethod::EMAIL->value)
        ->set('data.current_password', 'Secret123')
        ->set('data.current_factor_code', 'RECOVERY-123456')
        ->call('saveMethod')
        ->assertHasNoErrors()
        ->assertSet('pendingSetupMethod', TwoFactorMethod::EMAIL->value);

    $setup = TwoFactorSetup::query()->firstOrFail();
    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue()
        ->and($user->fresh()->two_factor_secret)->toBe($oldSecret)
        ->and($setup->method)->toBe(TwoFactorMethod::EMAIL);

    TwoFactorCode::query()
        ->where('purpose', $setup->emailPurpose())
        ->update(['code_hash' => Hash::make('654321')]);

    $component
        ->set('data.email_code', '654321')
        ->call('verifyEmailCode')
        ->assertHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeTrue()
        ->and($user->fresh()->two_factor_secret)->toBeNull();
});
