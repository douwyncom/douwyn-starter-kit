<?php

declare(strict_types=1);

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts both legacy boolean and canonical bool settings', function () {
    Settings::set('test', 'legacy_enabled', true, 'boolean');
    Settings::set('test', 'canonical_enabled', false, 'bool');

    expect(Settings::get('test', 'legacy_enabled'))->toBeTrue()
        ->and(Settings::get('test', 'canonical_enabled'))->toBeFalse();
});
