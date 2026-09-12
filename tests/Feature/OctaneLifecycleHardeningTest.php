<?php

declare(strict_types=1);

use App\Models\User;
use Douwyn\StarterKit\Contracts\PrivateStorageResolver;
use Douwyn\StarterKit\Contracts\SensitiveActionAuthorizer;
use Douwyn\StarterKit\Contracts\UserModelResolver;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Listeners\CreateConfigurationSandbox;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;

it('refreshes request scoped platform services between operations', function () {
    $firstAuthorizer = app(SensitiveActionAuthorizer::class);
    $firstStorageResolver = app(PrivateStorageResolver::class);

    expect(app(SensitiveActionAuthorizer::class))->toBe($firstAuthorizer)
        ->and(app(PrivateStorageResolver::class))->toBe($firstStorageResolver);

    app()->forgetScopedInstances();

    expect(app(SensitiveActionAuthorizer::class))->not->toBe($firstAuthorizer)
        ->and(app(PrivateStorageResolver::class))->not->toBe($firstStorageResolver);
});

it('resolves the user model from the current Octane operation configuration', function () {
    $worker = app();
    $firstResolver = $worker->make(UserModelResolver::class);
    $originalModel = $firstResolver->modelClass();

    (new FlushTemporaryContainerInstances)->handle(new TaskTerminated(
        $worker,
        $worker,
        fn () => null,
        null,
    ));

    $sandbox = clone $worker;
    (new CreateConfigurationSandbox)->handle(new TaskReceived($worker, $sandbox, fn () => null));
    $customModel = new class extends User {};
    $sandbox->make('config')->set('auth.providers.users.model', $customModel::class);

    expect($sandbox->make(UserModelResolver::class)->modelClass())->toBe($customModel::class)
        ->and($firstResolver->modelClass())->toBe($originalModel);
});

it('enables the permission cache reset listener for Octane operations', function () {
    expect(config('permission.register_octane_reset_listener'))->toBeTrue();
});
