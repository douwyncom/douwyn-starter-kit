<?php

declare(strict_types=1);

use App\Models\LoginSession;
use App\Models\User;
use App\Services\Auth\LoginSessionIdentifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createLoginSessionForIdentifierTest(
    User $user,
    string $id,
    ?DateTimeInterface $revokedAt = null,
): LoginSession {
    return LoginSession::query()->create([
        'id' => $id,
        'user_uuid' => $user->uuid,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Identifier test client',
        'last_active_at' => now(),
        'revoked_at' => $revokedAt,
    ]);
}

it('rejects malformed and unknown public identifiers', function (): void {
    $user = User::factory()->create();
    $identifiers = app(LoginSessionIdentifier::class);

    expect($identifiers->resolve('session-id'))->toBeNull()
        ->and($identifiers->resolve('douwyn_ls_'.str_repeat('g', 64)))->toBeNull()
        ->and($identifiers->resolve('douwyn_ls_'.str_repeat('a', 63)))->toBeNull()
        ->and($identifiers->resolve('douwyn_ls_'.str_repeat('a', 64)))->toBeNull()
        ->and($identifiers->resolveForUser($user, 'invalid'))->toBeNull()
        ->and($identifiers->rawIds(['invalid']))->toBe([]);
});

it('resolves a public identifier to the correct session and user', function (): void {
    $user = User::factory()->create();
    $session = createLoginSessionForIdentifierTest($user, 'browser-session-one');
    $identifiers = app(LoginSessionIdentifier::class);
    $identifier = $identifiers->forSession($session);

    expect($identifier)->toStartWith('douwyn_ls_')
        ->not->toContain((string) $session->getKey())
        ->and($identifiers->matches($identifier, (string) $session->getKey()))->toBeTrue()
        ->and($identifiers->resolve($identifier)?->is($session))->toBeTrue()
        ->and($identifiers->resolveForUser($user, $identifier)?->is($session))->toBeTrue();
});

it("does not resolve another user's or a revoked session for a user", function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherSession = createLoginSessionForIdentifierTest($otherUser, 'other-user-session');
    $revokedSession = createLoginSessionForIdentifierTest($user, 'revoked-session', now());
    $identifiers = app(LoginSessionIdentifier::class);

    expect($identifiers->resolveForUser(
        $user,
        $identifiers->forSession($otherSession),
    ))->toBeNull()
        ->and($identifiers->resolveForUser(
            $user,
            $identifiers->forSession($revokedSession),
        ))->toBeNull();
});

it('backfills the public digest when resolving a legacy session', function (): void {
    Schema::table('login_sessions', function (Blueprint $table): void {
        $table->string('public_id_hash', 64)->nullable()->change();
    });

    $user = User::factory()->create();
    $session = createLoginSessionForIdentifierTest($user, 'legacy-browser-session');
    DB::table('login_sessions')
        ->where('id', $session->getKey())
        ->update(['public_id_hash' => null]);

    $identifiers = app(LoginSessionIdentifier::class);
    $identifier = $identifiers->encode((string) $session->getKey());
    $resolved = $identifiers->resolveForUser($user, $identifier);

    expect($resolved?->is($session))->toBeTrue()
        ->and($resolved?->public_id_hash)->toBe($identifiers->digest((string) $session->getKey()))
        ->and(DB::table('login_sessions')->where('id', $session->getKey())->value('public_id_hash'))
        ->toBe($identifiers->digest((string) $session->getKey()));
});

it('maps opaque public identifiers back to unique raw session ids', function (): void {
    $user = User::factory()->create();
    $first = createLoginSessionForIdentifierTest($user, 'raw-session-one');
    $second = createLoginSessionForIdentifierTest($user, 'raw-session-two');
    $identifiers = app(LoginSessionIdentifier::class);
    $firstIdentifier = $identifiers->forSession($first);
    $secondIdentifier = $identifiers->forSession($second);

    $rawIds = $identifiers->rawIds([
        'invalid',
        $secondIdentifier,
        $firstIdentifier,
        $secondIdentifier,
        'douwyn_ls_'.str_repeat('f', 64),
    ]);

    expect($firstIdentifier)->not->toContain((string) $first->getKey())
        ->and($secondIdentifier)->not->toContain((string) $second->getKey())
        ->and($rawIds)->toHaveCount(2)
        ->and($rawIds)->toEqualCanonicalizing([
            (string) $second->getKey(),
            (string) $first->getKey(),
        ]);
});
