<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\Activitylog\CauserResolver;

/** Supplies the host audit infrastructure in isolated module test databases. */
final class ModuleActivityLogSchema
{
    public static function ensure(Application $app): void
    {
        if (! $app->getProvider(ActivitylogServiceProvider::class)) {
            if (! $app->bound('files')) {
                $app->register(FilesystemServiceProvider::class);
            }
            $app->register(ActivitylogServiceProvider::class);
            // Minimal module test containers have no authentication/session stack.
            $app->instance(AuthManager::class, new AuthManager($app));
            $app->make(CauserResolver::class)->resolveUsing(static fn () => null);
        }

        $schema = $app->make('db')->connection(config('activitylog.database_connection'))->getSchemaBuilder();
        if ($schema->hasTable(config('activitylog.table_name'))) {
            return;
        }
        $schema->create(config('activitylog.table_name'), static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('subject');
            $table->nullableUuidMorphs('causer');
            $table->uuid('batch_uuid')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
