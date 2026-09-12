<?php

use App\Support\ApiExceptionResponse;
use Douwyn\StarterKit\Contracts\LocaleResolver;
use Illuminate\Http\Request;

it('preserves module error codes and details when rendering API exceptions', function () {
    $this->mock(LocaleResolver::class)
        ->shouldReceive('resolveForApi')->once()->andReturn('en');

    $response = app(ApiExceptionResponse::class)->prepare(
        response()->json([
            'code' => 'module_balance_insufficient',
            'message' => 'Insufficient balance for this operation.',
            'available' => 100,
        ], 409),
        new RuntimeException('Module failure'),
        Request::create('/api/test-module-error'),
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toBe([
            'code' => 'module_balance_insufficient',
            'message' => 'Insufficient balance for this operation.',
            'available' => 100,
        ])
        ->and($response->headers->get('X-Request-ID'))->not->toBeNull();
});

it('still prepares an API error when locale resolution fails', function () {
    $this->mock(LocaleResolver::class)
        ->shouldReceive('resolveForApi')
        ->once()
        ->andThrow(new RuntimeException('Authentication database unavailable'));

    app()->setLocale('en');

    $response = app(ApiExceptionResponse::class)->prepare(
        response()->json(['message' => 'Server Error'], 500),
        new RuntimeException('Original failure'),
        Request::create('/api/v1/account'),
    );

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true)['code'])->toBe('internal_server_error')
        ->and($response->headers->get('X-Request-ID'))->not->toBeNull();
});
