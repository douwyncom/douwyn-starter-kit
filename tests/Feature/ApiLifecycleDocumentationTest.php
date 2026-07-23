<?php

use App\Models\User;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('documents request correlation and lifecycle response headers', function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    app(ApiErrorCodeRegistry::class)->register(
        'module_contract_error',
        409,
        'A module-defined contract error.',
    );

    $document = $this->actingAs($admin)
        ->getJson('/admin/api-docs/openapi.json')
        ->assertOk()
        ->json();

    $operation = $document['paths']['/meta/error-codes']['get'];
    $requestId = collect($operation['parameters'])
        ->first(fn (array $parameter): bool => $parameter['in'] === 'header'
            && $parameter['name'] === 'X-Request-ID');

    expect($requestId)->not->toBeNull()
        ->and(data_get($requestId, 'required', false))->toBeFalse()
        ->and(data_get($requestId, 'schema.format'))->toBe('uuid')
        ->and(data_get($operation, 'responses.200.content.application/json.schema.properties.data.type'))->toBe('array')
        ->and(data_get($operation, 'responses.200.content.application/json.schema.properties.data.items.properties.code.$ref'))->toBe('#/components/schemas/ApiErrorCode')
        ->and(data_get($operation, 'responses.200.content.application/json.schema.properties.data.items.properties.retryable.type'))->toBe('boolean')
        ->and($operation['responses']['200']['headers'])->toHaveKeys([
            'X-Request-ID',
            'X-API-Version',
            'Deprecation',
            'Sunset',
            'Link',
        ])
        ->and(data_get($document, 'components.schemas.ApiErrorCode.enum'))
        ->toContain('validation_failed', 'account_inactive', 'refresh_in_progress', 'module_contract_error');

    foreach (['ValidationException', 'AuthenticationException', 'ModelNotFoundException'] as $responseName) {
        $schema = data_get(
            $document,
            "components.responses.{$responseName}.content.application/json.schema",
        );

        expect(data_get($schema, 'properties.code.$ref'))
            ->toBe('#/components/schemas/ApiErrorCode')
            ->and(data_get($schema, 'required'))->toContain('code');
    }
});
