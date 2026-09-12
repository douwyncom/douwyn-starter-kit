<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\URL;

it('keeps the configured OpenAPI server relative across application origins', function (string $origin): void {
    config(['app.url' => $origin]);
    URL::useOrigin($origin);

    $configuration = Scramble::getGeneratorConfig(Scramble::DEFAULT_API)
        ->cloneWithoutExposing()
        ->config(config('scramble'))
        ->routes(fn (): bool => false);

    $document = app(Generator::class)($configuration);

    expect($document['servers'])->toBe([
        ['url' => '/api/v1', 'description' => 'Current host'],
    ]);
})->with(['http://local-starter.test', 'https://preview.example.com']);

it('preserves explicitly configured absolute OpenAPI servers alongside relative servers', function (): void {
    URL::useOrigin('http://local-starter.test');

    $configuration = Scramble::getGeneratorConfig(Scramble::DEFAULT_API)
        ->cloneWithoutExposing()
        ->config([
            ...config('scramble'),
            'servers' => [
                'Current host' => '/api/v1',
                'Production' => 'https://api.example.com/api/v1',
            ],
        ])
        ->routes(fn (): bool => false);

    $document = app(Generator::class)($configuration);

    expect($document['servers'])->toBe([
        ['url' => '/api/v1', 'description' => 'Current host'],
        ['url' => 'https://api.example.com/api/v1', 'description' => 'Production'],
    ]);
});
