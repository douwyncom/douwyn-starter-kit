<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Auth;

use Douwyn\StarterKit\Contracts\UserModelResolver;
use Douwyn\StarterKit\Platform;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class ConfiguredUserModelResolver implements UserModelResolver
{
    public function __construct(private Repository $config) {}

    public function modelClass(): string
    {
        $configuration = $this->providerConfiguration();
        $model = $configuration['model'] ?? null;

        if (! is_string($model)
            || ! is_subclass_of($model, Model::class)
            || ! is_subclass_of($model, Authenticatable::class)) {
            throw new LogicException(sprintf(
                'The [%s] auth guard must use an Eloquent user model implementing [%s].',
                Platform::AUTH_GUARD,
                Authenticatable::class,
            ));
        }

        /** @var class-string<Model> $model */
        return $model;
    }

    public function newModel(): Model
    {
        $model = $this->modelClass();

        return new $model;
    }

    public function table(): string
    {
        $table = $this->newModel()->getTable();

        if ($table === '') {
            throw new LogicException('The configured user model must declare a database table.');
        }

        return $table;
    }

    public function keyName(): string
    {
        $keyName = $this->newModel()->getKeyName();

        if ($keyName === '') {
            throw new LogicException('The configured user model must declare a primary key.');
        }

        return $keyName;
    }

    /** @return array<string, mixed> */
    private function providerConfiguration(): array
    {
        $provider = $this->config->get('auth.guards.'.Platform::AUTH_GUARD.'.provider');

        if (! is_string($provider) || $provider === '') {
            throw new LogicException(sprintf(
                'The [%s] auth guard must declare a user provider.',
                Platform::AUTH_GUARD,
            ));
        }

        $configuration = $this->config->get('auth.providers.'.$provider);

        if (! is_array($configuration)) {
            throw new LogicException(sprintf('The [%s] auth provider is not configured.', $provider));
        }

        return $configuration;
    }
}
