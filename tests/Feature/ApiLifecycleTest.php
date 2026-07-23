<?php

use App\Enums\ApiErrorCode;
use App\Support\ApiLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Route::get('api/v1/_lifecycle-probe', function (Request $request) {
        return response()->json([
            'request_id' => $request->attributes->get('request_id'),
            'log_context_request_id' => Context::get('request_id'),
        ]);
    });

    Route::get('_lifecycle-context-probe', fn () => response()->json([
        'request_id' => Context::get('request_id'),
    ]));
});

it('accepts a valid request id and exposes lifecycle context to the response and logs', function () {
    $requestId = (string) Str::uuid();

    $this->withHeader('X-Request-ID', $requestId)
        ->getJson('/api/v1/_lifecycle-probe')
        ->assertOk()
        ->assertHeader('X-Request-ID', $requestId)
        ->assertHeader('X-API-Version', '1.0.0')
        ->assertJsonPath('request_id', $requestId)
        ->assertJsonPath('log_context_request_id', $requestId)
        ->assertHeaderMissing('Deprecation')
        ->assertHeaderMissing('Sunset');
});

it('generates a uuid request id when the supplied value is invalid', function () {
    $response = $this->withHeader('X-Request-ID', 'not-a-uuid')
        ->getJson('/api/v1/_lifecycle-probe')
        ->assertOk();

    $requestId = (string) $response->headers->get('X-Request-ID');

    expect(Str::isUuid($requestId))->toBeTrue()
        ->and($requestId)->not->toBe('not-a-uuid')
        ->and($response->json('request_id'))->toBe($requestId)
        ->and($response->json('log_context_request_id'))->toBe($requestId);
});

it('clears request log context after the api request terminates', function () {
    $this->getJson('/api/v1/_lifecycle-probe')->assertOk();

    $this->getJson('/_lifecycle-context-probe')
        ->assertOk()
        ->assertJsonPath('request_id', null)
        ->assertHeaderMissing('X-Request-ID')
        ->assertHeaderMissing('X-API-Version');
});

it('emits standards compliant deprecation headers only when configured', function () {
    config()->set([
        'api.lifecycle.deprecated' => true,
        'api.lifecycle.deprecation_at' => '2027-01-15T00:00:00Z',
        'api.lifecycle.sunset_at' => '2027-07-15T00:00:00Z',
        'api.lifecycle.deprecation_documentation_url' => 'https://developer.example.com/migrations/v2',
    ]);

    $link = '<https://developer.example.com/migrations/v2>; rel="deprecation"; type="text/html"';

    $this->getJson('/api/v1/_lifecycle-probe')
        ->assertOk()
        ->assertHeader('Deprecation', '@'.strtotime('2027-01-15T00:00:00Z'))
        ->assertHeader('Sunset', (new DateTimeImmutable('2027-07-15T00:00:00Z'))->format(ApiLifecycle::HTTP_DATE_FORMAT))
        ->assertHeader('Link', $link);

    $error = $this->getJson('/api/v1/does-not-exist')->assertNotFound();

    expect($error->headers->all('Link'))->toBe([$link]);
});

it('omits invalid lifecycle dates instead of emitting invalid headers', function () {
    config()->set([
        'api.lifecycle.deprecated' => true,
        'api.lifecycle.deprecation_at' => 'invalid',
        'api.lifecycle.sunset_at' => '2027-07-15T00:00:00Z',
    ]);

    $this->getJson('/api/v1/_lifecycle-probe')
        ->assertOk()
        ->assertHeaderMissing('Deprecation')
        ->assertHeaderMissing('Sunset');
});

it('adds stable codes and correlation headers to framework generated api errors', function () {
    $notFound = $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::RESOURCE_NOT_FOUND->value);

    expect(Str::isUuid((string) $notFound->headers->get('X-Request-ID')))->toBeTrue();

    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', ApiErrorCode::AUTHENTICATION_REQUIRED->value);

    $this->postJson('/api/v1/auth/token/login')
        ->assertUnprocessable()
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_FAILED->value)
        ->assertJsonStructure(['message', 'code', 'errors']);
});

it('serves a machine readable error catalogue from the enum source of truth', function () {
    $response = $this->getJson('/api/v1/meta/error-codes')
        ->assertOk()
        ->assertHeader('X-API-Version', '1.0.0')
        ->assertJsonPath('meta.api_version', '1.0.0')
        ->assertJsonStructure([
            'data' => [
                '*' => ['code', 'http_status', 'description', 'retryable'],
            ],
            'meta' => ['api_version'],
        ]);

    expect(array_column($response->json('data'), 'code'))
        ->toBe(array_map(fn (ApiErrorCode $code): string => $code->value, ApiErrorCode::cases()));
});

it('keeps the human readable error catalogue synchronized with the enum', function () {
    $documentation = file_get_contents(base_path('docs/api-lifecycle.md'));

    foreach (ApiErrorCode::cases() as $code) {
        expect($documentation)->toContain('`'.$code->value.'`');
    }
});

it('allows and exposes lifecycle headers through cors', function () {
    expect(config('cors.allowed_headers'))->toContain('X-Request-ID')
        ->and(config('cors.exposed_headers'))->toContain(
            'X-Request-ID',
            'X-API-Version',
            'Deprecation',
            'Sunset',
            'Link',
        );
});
