<?php

use App\Enums\TwoFactorMethod;
use App\Models\LoginSession;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('issues the sanctum csrf cookie with credentialed cors headers', function () {
    $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Accept' => 'application/json',
    ])->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN')
        ->assertHeader('access-control-allow-origin', 'http://localhost:3000')
        ->assertHeader('access-control-allow-credentials', 'true');
});

it('rejects the nuxt session endpoint without a configured first party origin', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->postJson('/api/v1/auth/session/login', [
        'email' => $user->email,
        'password' => 'Secret123',
    ])->assertForbidden()->assertJsonPath('code', 'stateful_frontend_required');

    $this->assertGuest();
});

it('rate limits nuxt login attempts by ip even when the identity rotates', function () {
    foreach (range(1, 30) as $attempt) {
        $this->withHeaders($this->statefulHeaders)
            ->postJson('/api/v1/auth/session/login', [
                'email' => "rotating-{$attempt}@example.com",
            ])
            ->assertUnprocessable();
    }

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => 'rotating-31@example.com',
        ])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limit_exceeded');
});

it('logs nuxt in with a session while issuing no personal access token', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Chrome',
        ])
        ->assertOk()
        ->assertJsonPath('data.credential_type', 'session')
        ->assertJsonMissingPath('data.token');

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->tokens()->count())->toBe(0)
        ->and(LoginSession::query()->where('user_uuid', $user->uuid)->exists())->toBeTrue();

    $this->withHeaders($this->statefulHeaders)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.uuid', $user->uuid);
});

it('registers a nuxt user into a session without returning a bearer token', function () {
    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/register', [
            'email' => 'nuxt-user@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'first_name' => 'Nuxt',
            'last_name' => 'User',
            'device_name' => 'Nuxt Chrome',
        ])
        ->assertCreated()
        ->assertJsonPath('data.credential_type', 'session')
        ->assertJsonMissingPath('data.token');

    $user = User::query()->where('email', 'nuxt-user@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->tokens()->count())->toBe(0);
});

it('completes a session two factor challenge without resending the password', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $challengeToken = $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Chrome',
        ])
        ->assertStatus(202)
        ->assertJsonPath('code', 'two_factor_required')
        ->json('data.challenge_token');

    $this->assertGuest();

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/challenges/verify', [
            'challenge_token' => $challengeToken,
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->assertJsonPath('data.credential_type', 'session');

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->tokens()->count())->toBe(0);
});

it('handles token listing and password changes from a transient session token', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $mobileToken = $user->createToken('iPhone')->plainTextToken;

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

    $this->withHeaders($this->statefulHeaders)
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->assertJsonPath('data.0.current', false);

    $this->withHeaders($this->statefulHeaders)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Secret123',
            'password' => 'Changed123',
            'password_confirmation' => 'Changed123',
        ])->assertOk();

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->fresh()->tokens()->count())->toBe(0);

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/logout')
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($mobileToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('logs out only the current nuxt session', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $mobileToken = $user->createToken('iPhone')->plainTextToken;

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/logout')
        ->assertOk();

    $this->assertGuest('web');
    $this->app['auth']->forgetGuards();
    $this->withToken($mobileToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('handles inactive stateful users without treating a transient token as a model', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

    $user->forceFill(['is_inactive' => true])->save();
    $this->app['auth']->forgetGuards();

    $this->withHeaders($this->statefulHeaders)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');
});

it('rejects bearer-only logout from a transient session without a server error', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(409)
        ->assertJsonPath('code', 'token_credential_required');

    $this->assertAuthenticatedAs($user, 'web');
});
