<?php

use App\Enums\TwoFactorMethod;
use App\Models\TwoFactorCode;
use App\Models\TwoFactorSetup;
use App\Models\User;
use App\Services\Auth\AccessRevocationService;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

function accountSecurityToken(User $user, string $name = 'Test device'): string
{
    return $user->createToken($name, ['user:read', 'user:update'])->plainTextToken;
}

function enableAppTwoFactor(User $user, string $secret): User
{
    $user->forceFill([
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
        'two_factor_last_used_timestamp' => null,
    ])->save();

    return $user->fresh();
}

function createCurrentEmailCode(User $user, string $code = '123456'): TwoFactorCode
{
    return TwoFactorCode::query()->create([
        'user_uuid' => $user->uuid,
        'channel' => 'email',
        'sent_to' => $user->email,
        'purpose' => 'account_security.current',
        'code_hash' => Hash::make($code),
        'expires_at' => now()->addMinutes(5),
    ]);
}

it('returns security status without exposing secrets or recovery codes', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = enableAppTwoFactor(User::factory()->create(), $secret);
    $token = accountSecurityToken($user);

    $response = $this->withToken($token)
        ->getJson('/api/v1/account/security')
        ->assertOk()
        ->assertJsonPath('data.two_factor.enabled', true)
        ->assertJsonPath('data.two_factor.method', 'app')
        ->assertJsonPath('data.two_factor.recovery_codes_remaining', 1);

    expect($response->content())
        ->not->toContain($secret)
        ->not->toContain('sha256:')
        ->not->toContain('RECOVERY-123456');
});

it('requires the update ability for account security mutations', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $readOnlyToken = $user->createToken('Read only', ['user:read'])->plainTextToken;

    $this->withToken($readOnlyToken)
        ->getJson('/api/v1/account/security')
        ->assertOk();

    $this->withToken($readOnlyToken)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertForbidden();
});

it('supports authenticator setup through the Nuxt stateful session flow', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $headers = [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/account/security',
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Security Settings',
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $setup = $this->withHeaders($headers)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->withHeaders($headers)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setup->json('data.setup_token'),
            'otp' => TwoFactor::google2fa()->getCurrentOtp($setup->json('data.secret')),
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.two_factor.method', 'app');

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('sets up an authenticator without changing the active state before confirmation', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $token = accountSecurityToken($user);

    $setup = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.method', 'app')
        ->assertJsonStructure(['data' => ['setup_token', 'secret', 'otpauth_uri', 'expires_at']]);

    $user->refresh();
    $secret = $setup->json('data.secret');
    $setupToken = $setup->json('data.setup_token');

    expect($user->two_factor_method)->toBe(TwoFactorMethod::NONE)
        ->and($user->hasEnabledTwoFactor())->toBeFalse()
        ->and(TwoFactorSetup::query()->firstOrFail()->secret)->toBe($secret)
        ->and((string) TwoFactorSetup::query()->toBase()->value('secret'))->not->toBe($secret);

    $otp = TwoFactor::google2fa()->getCurrentOtp($secret);
    $confirmed = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setupToken,
            'otp' => $otp,
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.two_factor.enabled', true)
        ->assertJsonPath('data.two_factor.method', 'app')
        ->assertJsonCount(10, 'data.recovery_codes');

    $rawCodes = $confirmed->json('data.recovery_codes');
    $storedCodes = $user->fresh()->two_factor_recovery_codes;

    expect($storedCodes)->each->toStartWith('sha256:')
        ->and(array_intersect($rawCodes, $storedCodes))->toBeEmpty()
        ->and($user->fresh()->two_factor_last_used_timestamp)->not->toBeNull();

    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setupToken,
            'otp' => $otp,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('setup_token');
});

it('keeps an active email factor until its app replacement is confirmed', function () {
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
    ]);
    createCurrentEmailCode($user);
    $token = accountSecurityToken($user);

    $setup = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
            'otp' => '123456',
        ])
        ->assertCreated();

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::EMAIL))->toBeTrue();

    $secret = $setup->json('data.secret');
    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setup->json('data.setup_token'),
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->assertJsonPath('data.two_factor.method', 'app');

    expect($user->fresh()->hasEnabledTwoFactor(TwoFactorMethod::APP))->toBeTrue();
});

it('sets up email two factor with a code scoped to the pending setup', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['password' => 'Secret123']);
    $token = accountSecurityToken($user);

    $setup = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/email/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.method', 'email')
        ->assertJsonMissingPath('data.secret');

    $pending = TwoFactorSetup::query()->firstOrFail();
    $code = TwoFactorCode::query()->where('purpose', $pending->emailPurpose())->firstOrFail();
    $code->forceFill(['code_hash' => Hash::make('654321')])->save();

    $confirmed = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/email/confirm', [
            'setup_token' => $setup->json('data.setup_token'),
            'otp' => '654321',
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.two_factor.method', 'email')
        ->assertJsonCount(10, 'data.recovery_codes');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($confirmed->json('data.recovery_codes.0'))->not->toStartWith('sha256:');
});

it('requires the current factor for sensitive mutations and disables it atomically', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = enableAppTwoFactor(User::factory()->create(['password' => 'Secret123']), $secret);
    $token = accountSecurityToken($user);

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/two-factor', [
            'current_password' => 'Secret123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp');

    expect($user->fresh()->hasEnabledTwoFactor())->toBeTrue();

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/two-factor', [
            'current_password' => 'Secret123',
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->assertJsonPath('data.two_factor.enabled', false)
        ->assertJsonPath('data.two_factor.method', 'none');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_enabled_at)->toBeNull();
});

it('rolls back a two factor change when access revocation fails', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = enableAppTwoFactor(User::factory()->create(['password' => 'Secret123']), $secret);
    $token = accountSecurityToken($user);
    $revocation = Mockery::mock(AccessRevocationService::class);
    $revocation->shouldReceive('revokeOtherAccess')
        ->once()
        ->andThrow(new RuntimeException('Simulated revocation failure.'));
    $this->app->instance(AccessRevocationService::class, $revocation);

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/two-factor', [
            'current_password' => 'Secret123',
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertInternalServerError();

    expect($user->fresh()->hasEnabledTwoFactor())->toBeTrue()
        ->and(PersonalAccessToken::findToken($token))->not->toBeNull();
});

it('regenerates recovery codes once, stores only hashes, and revokes other access', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = enableAppTwoFactor(User::factory()->create(['password' => 'Secret123']), $secret);
    $currentToken = accountSecurityToken($user, 'Current');
    $otherToken = accountSecurityToken($user, 'Other');

    $response = $this->withToken($currentToken)
        ->postJson('/api/v1/account/security/recovery-codes/regenerate', [
            'current_password' => 'Secret123',
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonCount(10, 'data.recovery_codes');

    $rawCodes = $response->json('data.recovery_codes');
    $storedCodes = $user->fresh()->two_factor_recovery_codes;

    expect($storedCodes)->each->toStartWith('sha256:')
        ->and(array_intersect($rawCodes, $storedCodes))->toBeEmpty()
        ->and(PersonalAccessToken::findToken($otherToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($currentToken))->not->toBeNull();
});

it('persists failed setup attempts and consumes the setup after five failures', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $token = accountSecurityToken($user);

    $setup = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertCreated();

    $validOtp = TwoFactor::google2fa()->getCurrentOtp($setup->json('data.secret'));
    $wrongOtp = $validOtp === '000000' ? '000001' : '000000';

    foreach (range(1, 5) as $_) {
        $this->withToken($token)
            ->postJson('/api/v1/account/security/two-factor/app/confirm', [
                'setup_token' => $setup->json('data.setup_token'),
                'otp' => $wrongOtp,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');
    }

    $pending = TwoFactorSetup::query()->firstOrFail();
    expect($pending->attempts)->toBe(5)
        ->and($pending->consumed_at)->not->toBeNull();

    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setup->json('data.setup_token'),
            'otp' => $validOtp,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('setup_token');
});

it('sends a password-protected code for the current email factor', function () {
    Mail::fake();

    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $token = accountSecurityToken($user);

    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/email/current-code', [
            'current_password' => 'wrong-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/email/current-code', [
            'current_password' => 'Secret123',
        ])
        ->assertOk();

    expect(TwoFactorCode::query()
        ->where('user_uuid', $user->uuid)
        ->where('purpose', 'account_security.current')
        ->exists())->toBeTrue();
});

it('invalidates a pending setup when the authentication state changes', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $token = accountSecurityToken($user);

    $setup = $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/setup', [
            'current_password' => 'Secret123',
        ])
        ->assertCreated();

    $user->forceFill(['password' => 'Changed123'])->save();

    $this->withToken($token)
        ->postJson('/api/v1/account/security/two-factor/app/confirm', [
            'setup_token' => $setup->json('data.setup_token'),
            'otp' => TwoFactor::google2fa()->getCurrentOtp($setup->json('data.secret')),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('setup_token');

    expect(TwoFactorSetup::query()->firstOrFail()->consumed_at)->not->toBeNull()
        ->and($user->fresh()->hasEnabledTwoFactor())->toBeFalse();
});

it('accepts a hashed recovery code as the current factor', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = enableAppTwoFactor(User::factory()->create(['password' => 'Secret123']), $secret);
    $token = accountSecurityToken($user);

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/two-factor', [
            'current_password' => 'Secret123',
            'recovery_code' => 'recovery123456',
        ])
        ->assertOk()
        ->assertJsonPath('data.two_factor.enabled', false);
});
