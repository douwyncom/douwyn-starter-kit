<?php

declare(strict_types=1);

use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;

it('extends error codes idempotently and rejects conflicting definitions', function () {
    $registry = (new ApiErrorCodeRegistry)
        ->register('ledger_unavailable', 503, 'The ledger is temporarily unavailable.', true)
        ->register('ledger_unavailable', 503, 'The ledger is temporarily unavailable.', true);

    expect($registry->codes())->toBe(['ledger_unavailable'])
        ->and($registry->catalogue()[0])->toBe([
            'code' => 'ledger_unavailable',
            'http_status' => 503,
            'description' => 'The ledger is temporarily unavailable.',
            'retryable' => true,
        ])
        ->and(fn () => $registry->register(
            'ledger_unavailable',
            409,
            'A conflicting definition.',
        ))->toThrow(LogicException::class, 'already registered differently');
});

it('extends token abilities by profile without duplicates', function () {
    $registry = new TokenAbilityRegistry([
        TokenAbilityProfile::LEGACY->value => ['user:read', 'user:update'],
        TokenAbilityProfile::MOBILE->value => ['user:read', 'devices:read'],
    ]);

    $registry->extend(TokenAbilityProfile::MOBILE, ['ledger:read', 'user:read']);

    expect($registry->abilitiesFor(TokenAbilityProfile::LEGACY))->toBe([
        'user:read',
        'user:update',
    ])->and($registry->abilitiesFor(TokenAbilityProfile::MOBILE))->toBe([
        'user:read',
        'devices:read',
        'ledger:read',
    ]);
});
