<?php

use App\Enums\AuthCredentialType;
use App\Enums\TwoFactorMethod;
use App\Models\AuthChallenge;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\Auth\AuthChallengeService;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
    $this->statefulHeaders = [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
});

function challengeTotpUser(array $attributes = []): array
{
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
        ...$attributes,
    ]);

    return [$user, $secret];
}

function issueTokenChallenge($test, User $user): string
{
    return $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Security test',
    ])
        ->assertStatus(202)
        ->assertJsonPath('code', 'two_factor_required')
        ->json('data.challenge_token');
}

function issueSessionChallenge($test, User $user): string
{
    return $test->withHeaders($test->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt security test',
        ])
        ->assertStatus(202)
        ->assertJsonPath('code', 'two_factor_required')
        ->json('data.challenge_token');
}

it('returns an opaque challenge credential while storing only its hash', function () {
    [$user] = challengeTotpUser();

    $plainTextToken = issueTokenChallenge($this, $user);
    $challenge = AuthChallenge::query()->sole();

    expect($plainTextToken)
        ->toStartWith('douwyn_ch_')
        ->not->toBe($challenge->uuid)
        ->and(strlen($plainTextToken))->toBeGreaterThanOrEqual(64)
        ->and($challenge->token_hash)->toBe(hash('sha256', $plainTextToken))
        ->and($challenge->token_hash)->not->toContain($plainTextToken)
        ->and($challenge->toArray())->not->toHaveKey('token_hash')
        ->and(AuthChallenge::query()->where('token_hash', $plainTextToken)->exists())->toBeFalse();
});

it('commits a failed verification attempt instead of rolling it back with validation', function () {
    [$user] = challengeTotpUser();
    $plainTextToken = issueTokenChallenge($this, $user);

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $plainTextToken,
        'otp' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors('otp');

    $challenge = AuthChallenge::query()->sole();

    expect($challenge->attempts)->toBe(1)
        ->and($challenge->consumed_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects expired and already consumed challenges without issuing another credential', function () {
    [$user, $secret] = challengeTotpUser();
    $expiredToken = issueTokenChallenge($this, $user);
    $expired = AuthChallenge::query()->sole();
    $expired->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $expiredToken,
        'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
    ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');

    expect($user->tokens()->count())->toBe(0);

    $validToken = issueTokenChallenge($this, $user);
    $otp = TwoFactor::google2fa()->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $validToken,
        'otp' => $otp,
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);

    $issuedTokenCount = $user->tokens()->count();

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $validToken,
        'otp' => $otp,
    ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');

    expect($user->tokens()->count())->toBe($issuedTokenCount)
        ->and(AuthChallenge::query()->where('token_hash', hash('sha256', $validToken))->value('consumed_at'))
        ->not->toBeNull();
});

it('invalidates a pending challenge when password or two factor state changes', function (string $mutation) {
    [$user, $secret] = challengeTotpUser();
    $plainTextToken = issueTokenChallenge($this, $user);

    if ($mutation === 'password') {
        $user->forceFill(['password' => 'Changed123'])->save();
    } else {
        $user->forceFill([
            'two_factor_method' => TwoFactorMethod::NONE,
            'two_factor_confirmed_at' => null,
            'two_factor_enabled_at' => null,
        ])->save();
    }

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $plainTextToken,
        'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
    ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');

    expect(AuthChallenge::query()->sole()->consumed_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
})->with(['password', 'two-factor']);

it('does not issue a challenge from a stale successful first factor', function (string $mutation) {
    [$user] = challengeTotpUser();
    $authenticatedSnapshot = User::query()->findOrFail($user->uuid);

    if ($mutation === 'password') {
        $user->forceFill(['password' => 'Changed123'])->save();
    } else {
        $user->forceFill(['email' => 'changed@example.com'])->save();
    }

    $request = Request::create('/api/v1/auth/login', 'POST');

    try {
        app(AuthChallengeService::class)->issue(
            $authenticatedSnapshot,
            AuthCredentialType::TOKEN,
            $request,
            'Stale first factor',
        );

        $this->fail('A stale first factor unexpectedly issued a challenge.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401)
            ->and($exception->getResponse()->getData(true)['code'])
            ->toBe('authentication_state_changed');
    }

    expect(AuthChallenge::query()->exists())->toBeFalse();
})->with(['password', 'email']);

it('requires exactly one otp or recovery code and does not spend an attempt on malformed input', function () {
    [$user] = challengeTotpUser([
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-ONE']),
    ]);
    $plainTextToken = issueTokenChallenge($this, $user);

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $plainTextToken,
    ])->assertUnprocessable()->assertJsonValidationErrors(['otp', 'recovery_code']);

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $plainTextToken,
        'otp' => '123456',
        'recovery_code' => 'RECOVERY-ONE',
    ])->assertUnprocessable()->assertJsonValidationErrors(['otp', 'recovery_code']);

    expect(AuthChallenge::query()->sole()->attempts)->toBe(0)
        ->and(AuthChallenge::query()->sole()->consumed_at)->toBeNull()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(1);
});

it('binds a session challenge to its pre-auth session', function () {
    [$user, $secret] = challengeTotpUser();
    $plainTextToken = issueSessionChallenge($this, $user);

    $this->withSession(['auth_challenge_binding' => 'a-different-browser-session'])
        ->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/challenges/verify', [
            'challenge_token' => $plainTextToken,
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('challenge_token');

    $this->assertGuest('web');
    expect(AuthChallenge::query()->sole()->consumed_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects session challenge verification from a non-stateful origin without consuming it', function () {
    [$user, $secret] = challengeTotpUser();
    $plainTextToken = issueSessionChallenge($this, $user);

    $this->flushHeaders();
    $this->postJson('/api/v1/auth/session/challenges/verify', [
        'challenge_token' => $plainTextToken,
        'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
    ])->assertForbidden()->assertJsonPath('code', 'stateful_frontend_required');

    $this->assertGuest('web');
    expect(AuthChallenge::query()->sole()->consumed_at)->toBeNull();
});

it('isolates email verification codes by challenge purpose', function () {
    Mail::fake();

    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $tokenChallengeToken = issueTokenChallenge($this, $user);
    $sessionChallengeToken = issueSessionChallenge($this, $user);
    $tokenChallenge = AuthChallenge::query()
        ->where('token_hash', hash('sha256', $tokenChallengeToken))
        ->firstOrFail();
    $sessionChallenge = AuthChallenge::query()
        ->where('token_hash', hash('sha256', $sessionChallengeToken))
        ->firstOrFail();

    TwoFactorCode::query()
        ->where('purpose', $tokenChallenge->emailPurpose())
        ->update(['code_hash' => Hash::make('111111')]);
    TwoFactorCode::query()
        ->where('purpose', $sessionChallenge->emailPurpose())
        ->update(['code_hash' => Hash::make('222222')]);

    $this->flushHeaders();
    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $tokenChallengeToken,
        'otp' => '222222',
    ])->assertUnprocessable()->assertJsonValidationErrors('otp');

    expect($tokenChallenge->fresh()->attempts)->toBe(1)
        ->and(TwoFactorCode::query()->where('purpose', $sessionChallenge->emailPurpose())->value('consumed_at'))
        ->toBeNull();

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/challenges/verify', [
            'challenge_token' => $sessionChallengeToken,
            'otp' => '222222',
        ])->assertOk()->assertJsonPath('data.credential_type', 'session');

    $this->flushHeaders();
    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $tokenChallengeToken,
        'otp' => '111111',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});
