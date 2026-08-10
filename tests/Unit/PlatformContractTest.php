<?php

declare(strict_types=1);

use Douwyn\StarterKit\Platform;

it('keeps the platform capability and module scaffold synchronized', function () {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $moduleComposer = json_decode(
        file_get_contents($root.'/stubs/module/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['provide'][Platform::CAPABILITY])->toBe(Platform::VERSION)
        ->and($composer['require'])->toHaveKey('league/flysystem-aws-s3-v3')
        ->and($moduleComposer['require'][Platform::CAPABILITY])->toBe('^2.2')
        ->and(Platform::supports($moduleComposer['require'][Platform::CAPABILITY]))->toBeTrue();
});

it('publishes the additive Platform contracts in metadata', function () {
    expect(Platform::metadata()['invariants'])->toMatchArray([
        'api_localized_middleware' => Platform::API_LOCALIZED_MIDDLEWARE,
        'panel_access' => 'permission:panel.access',
        'media_disk_config' => Platform::MEDIA_DISK_CONFIG,
        'private_disk_config' => Platform::PRIVATE_DISK_CONFIG,
    ]);
});
