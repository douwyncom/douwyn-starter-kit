<?php

declare(strict_types=1);

use App\Support\ActivityLogSanitizer;

it('recursively redacts raw secret aliases and credential-shaped values', function (): void {
    $credential = 'DWL1.'.str_repeat('A', 16).'.'.str_repeat('B', 43);
    $safeUuid = '0198f000-0000-7000-8000-000000000001';
    $sanitized = ActivityLogSanitizer::sanitize([
        'license_key' => 'raw-license-key',
        'activationToken' => 'raw-activation-token',
        'nested' => [
            'raw-credential' => 'raw-credential',
            'authorization' => 'Bearer raw-secret',
            'message' => 'Verification failed for '.$credential.'.',
            'values' => [$credential, 'safe-value'],
            'license_uuid' => $safeUuid,
            'effect_key' => 'order-settled:'.$safeUuid,
        ],
        'object' => (object) [
            'integration_secret' => 'raw-integration-secret',
        ],
    ]);

    expect($sanitized)->toMatchArray([
        'license_key' => '[REDACTED]',
        'activationToken' => '[REDACTED]',
        'nested' => [
            'raw-credential' => '[REDACTED]',
            'authorization' => '[REDACTED]',
            'message' => '[REDACTED]',
            'values' => ['[REDACTED]', 'safe-value'],
            'license_uuid' => $safeUuid,
            'effect_key' => 'order-settled:'.$safeUuid,
        ],
        'object' => [
            'integration_secret' => '[REDACTED]',
        ],
    ]);
});

it('does not mistake UUID references and ordinary dotted values for credentials', function (): void {
    $values = [
        'license_uuid' => '0198f000-0000-7000-8000-000000000001',
        'activation_uuid' => '0198f000-0000-7000-8000-000000000002',
        'version' => '1.2.3',
        'hostname' => 'downloads.example.test',
        'effect_key' => 'license-renewed:0198f000-0000-7000-8000-000000000001',
    ];

    expect(ActivityLogSanitizer::sanitize($values))->toBe($values);
});
