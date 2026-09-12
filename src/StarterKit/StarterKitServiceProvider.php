<?php

declare(strict_types=1);

namespace Douwyn\StarterKit;

use App\Enums\ApiErrorCode;
use App\Services\Security\StarterKitSensitiveActionAuthorizer;
use App\Services\System\StarterKitSystemInsightsReader;
use App\Support\StarterKitLocaleResolver;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Douwyn\StarterKit\Auth\ConfiguredUserModelResolver;
use Douwyn\StarterKit\Auth\PermissionPanelAccessResolver;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Douwyn\StarterKit\Console\PlatformStatusCommand;
use Douwyn\StarterKit\Contracts\LocaleResolver;
use Douwyn\StarterKit\Contracts\PanelAccessResolver;
use Douwyn\StarterKit\Contracts\PrivateStorageResolver;
use Douwyn\StarterKit\Contracts\SensitiveActionAuthorizer;
use Douwyn\StarterKit\Contracts\SystemInsightsReader;
use Douwyn\StarterKit\Contracts\UserModelResolver;
use Douwyn\StarterKit\Modules\ModuleRegistry;
use Douwyn\StarterKit\Storage\ConfiguredPrivateStorageResolver;
use Illuminate\Support\ServiceProvider;

final class StarterKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Platform::assertHost();

        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(
            ApiErrorCodeRegistry::class,
            static fn (): ApiErrorCodeRegistry => (new ApiErrorCodeRegistry)
                ->registerMany(ApiErrorCode::catalogue()),
        );
        $this->app->singleton(
            TokenAbilityRegistry::class,
            static fn (): TokenAbilityRegistry => new TokenAbilityRegistry([
                TokenAbilityProfile::LEGACY->value => [
                    'user:read',
                    'user:update',
                ],
                TokenAbilityProfile::MOBILE->value => [
                    'user:read',
                    'user:update',
                    'devices:read',
                    'devices:revoke',
                ],
            ]),
        );
        $this->app->scoped(UserModelResolver::class, ConfiguredUserModelResolver::class);
        $this->app->singleton(LocaleResolver::class, StarterKitLocaleResolver::class);
        $this->app->singleton(PanelAccessResolver::class, PermissionPanelAccessResolver::class);
        $this->app->scoped(PrivateStorageResolver::class, ConfiguredPrivateStorageResolver::class);
        $this->app->scoped(SensitiveActionAuthorizer::class, StarterKitSensitiveActionAuthorizer::class);
        $this->app->scoped(SystemInsightsReader::class, StarterKitSystemInsightsReader::class);
    }

    public function boot(ModuleRegistry $modules): void
    {
        // Resolving the registry here executes compatibility checks registered
        // by every auto-discovered module before the application can serve work.
        (void) $modules->count();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PlatformStatusCommand::class,
            ]);
        }
    }
}
