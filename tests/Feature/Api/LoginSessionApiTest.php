<?php

use App\Models\LoginSession;
use App\Models\User;
use App\Services\Auth\LoginSessionIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
    config()->set('session.driver', 'database');
    app('session')->forgetDrivers();
    $this->statefulHeaders = [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/account/security',
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
});

function createBrowserLoginSession(
    User $user,
    string $rawId,
    ?string $userAgent = null,
    mixed $lastActiveAt = null,
    mixed $revokedAt = null,
): LoginSession {
    return LoginSession::query()->create([
        'id' => $rawId,
        'user_uuid' => $user->uuid,
        'ip_address' => '203.0.113.10',
        'user_agent' => $userAgent ?? 'Mozilla/5.0 Chrome/123 Windows',
        'last_active_at' => $lastActiveAt ?? now(),
        'revoked_at' => $revokedAt,
    ]);
}

function establishStatefulBrowserSession($test, User $user): LoginSession
{
    $test->actingAs($user, 'web')->withSession(['stateful_test' => true]);
    $rawSessionId = session()->getId();
    session()->put('login_session_registry_id', $rawSessionId);
    session()->save();
    $test->withCredentials()->withCookie((string) config('session.cookie'), $rawSessionId);

    return createBrowserLoginSession($user, $rawSessionId);
}

it('lists only active sessions owned by the user without exposing raw session ids', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $otherUser = User::factory()->create();

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Chrome',
        ])->assertOk();

    $current = LoginSession::query()->where('user_uuid', $user->uuid)->firstOrFail();
    $otherRawId = 'raw-session-id-that-must-never-leak';
    createBrowserLoginSession($user, $otherRawId, $otherRawId);
    createBrowserLoginSession($user, 'revoked-session', revokedAt: now());
    createBrowserLoginSession($user, 'expired-session', lastActiveAt: now()->subHours(3));
    createBrowserLoginSession($otherUser, 'different-owner-session');

    $response = $this->withHeaders($this->statefulHeaders)
        ->getJson('/api/v1/account/security/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('context.credential_type', 'session');

    $sessions = collect($response->json('data'));

    expect($sessions->where('current', true))->toHaveCount(1)
        ->and($sessions->where('current', false))->toHaveCount(1);

    foreach ($sessions->pluck('id') as $identifier) {
        expect($identifier)->toMatch('/^douwyn_ls_[a-f0-9]{64}$/');
    }

    expect($response->content())
        ->not->toContain((string) $current->getKey())
        ->not->toContain($otherRawId)
        ->not->toContain('different-owner-session')
        ->not->toContain('user_agent');
});

it('shows no current browser session when listing through a bearer token', function () {
    $user = User::factory()->create();
    createBrowserLoginSession($user, 'browser-one');
    createBrowserLoginSession($user, 'browser-two');
    $token = $user->createToken('Mobile')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/account/security/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('context.credential_type', 'token');

    expect(collect($response->json('data'))->where('current', true))->toBeEmpty();
});

it('allows a read-only bearer to list sessions but forbids every revocation operation', function () {
    $user = User::factory()->create();
    $session = createBrowserLoginSession($user, 'read-only-browser');
    $identifier = app(LoginSessionIdentifier::class)->encode($session->getKey());
    $readOnlyToken = $user->createToken('Read only', ['user:read'])->plainTextToken;

    $this->withToken($readOnlyToken)
        ->getJson('/api/v1/account/security/sessions')
        ->assertOk();

    $this->withToken($readOnlyToken)
        ->deleteJson("/api/v1/account/security/sessions/{$identifier}")
        ->assertForbidden();

    $this->withToken($readOnlyToken)
        ->deleteJson('/api/v1/account/security/sessions/others')
        ->assertForbidden();

    $this->withToken($readOnlyToken)
        ->deleteJson('/api/v1/account/security/sessions')
        ->assertForbidden();

    expect($session->fresh()->revoked_at)->toBeNull();
});

it('revokes only an owner scoped opaque identifier and rejects raw or foreign ids', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $owned = createBrowserLoginSession($user, 'owned-raw-session');
    $foreign = createBrowserLoginSession($otherUser, 'foreign-raw-session');
    $token = $user->createToken('Mobile')->plainTextToken;
    $identifiers = app(LoginSessionIdentifier::class);

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/sessions/'.$identifiers->encode($owned->getKey()))
        ->assertOk()
        ->assertJsonPath('data.current_session_revoked', false);

    expect($owned->fresh()->revoked_at)->not->toBeNull();

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/sessions/'.$identifiers->encode($foreign->getKey()))
        ->assertNotFound();

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/sessions/'.$foreign->getKey())
        ->assertNotFound();

    expect($foreign->fresh()->revoked_at)->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('logs out and invalidates the cookie when revoking the current stateful session', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $current = establishStatefulBrowserSession($this, $user);
    $identifier = app(LoginSessionIdentifier::class)->encode($current->getKey());

    $response = $this->withHeaders($this->statefulHeaders)
        ->deleteJson("/api/v1/account/security/sessions/{$identifier}")
        ->assertOk()
        ->assertJsonPath('data.current_session_revoked', true);

    $this->assertGuest('web');
    expect($current->fresh()->revoked_at)->not->toBeNull()
        ->and($response->content())->not->toContain((string) $current->getKey());
});

it('preserves the current stateful session when revoking other browser sessions', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $otherUser = User::factory()->create();
    $current = establishStatefulBrowserSession($this, $user);

    $first = createBrowserLoginSession($user, 'other-one');
    $second = createBrowserLoginSession($user, 'other-two');
    $foreign = createBrowserLoginSession($otherUser, 'foreign');

    $this->withHeaders($this->statefulHeaders)
        ->deleteJson('/api/v1/account/security/sessions/others')
        ->assertOk()
        ->assertJsonPath('data.revoked_count', 2)
        ->assertJsonPath('data.current_session_revoked', false);

    $this->assertAuthenticatedAs($user, 'web');
    expect($current->fresh()->revoked_at)->toBeNull()
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->fresh()->revoked_at)->not->toBeNull()
        ->and($foreign->fresh()->revoked_at)->toBeNull();
});

it('treats every browser session as other when invoked with a bearer token', function () {
    $user = User::factory()->create();
    $first = createBrowserLoginSession($user, 'browser-one');
    $second = createBrowserLoginSession($user, 'browser-two');
    $token = $user->createToken('Mobile')->plainTextToken;

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/sessions/others')
        ->assertOk()
        ->assertJsonPath('data.revoked_count', 2)
        ->assertJsonPath('data.current_session_revoked', false);

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->fresh()->revoked_at)->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('revokes all browser sessions and logs out a stateful caller', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->withHeaders($this->statefulHeaders)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

    $current = LoginSession::query()->where('user_uuid', $user->uuid)->firstOrFail();
    $other = createBrowserLoginSession($user, 'other-browser');

    $this->withHeaders($this->statefulHeaders)
        ->deleteJson('/api/v1/account/security/sessions')
        ->assertOk()
        ->assertJsonPath('data.revoked_count', 2)
        ->assertJsonPath('data.current_session_revoked', true);

    $this->assertGuest('web');
    expect($current->fresh()->revoked_at)->not->toBeNull()
        ->and($other->fresh()->revoked_at)->not->toBeNull();
});

it('revokes all browser sessions without deleting the bearer credential', function () {
    $user = User::factory()->create();
    $first = createBrowserLoginSession($user, 'browser-one');
    $second = createBrowserLoginSession($user, 'browser-two');
    $token = $user->createToken('Mobile')->plainTextToken;

    $this->withToken($token)
        ->deleteJson('/api/v1/account/security/sessions')
        ->assertOk()
        ->assertJsonPath('data.revoked_count', 2)
        ->assertJsonPath('data.current_session_revoked', false);

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->fresh()->revoked_at)->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});
