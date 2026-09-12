<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\System\StarterKitSystemInsightsReader;
use Carbon\CarbonImmutable;
use Douwyn\StarterKit\Contracts\SystemInsightsReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function systemInsightsActor(array $permissions = ['users.view', 'system.queue.view', 'panel.access']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

it('binds the host reader without depending on a private module', function (): void {
    expect(app(SystemInsightsReader::class))->toBeInstanceOf(StarterKitSystemInsightsReader::class);
});

it('counts exact UTC registration bounds and computes comparison without exposing user records', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-11T12:00:00Z'));
    $actor = systemInsightsActor();
    foreach ([
        ['2026-08-28T16:59:59Z', false], ['2026-08-28T17:00:00Z', false], ['2026-08-31T16:59:59Z', true],
        ['2026-08-31T17:00:00Z', false], ['2026-09-01T10:00:00Z', false], ['2026-09-03T16:59:59Z', true],
        ['2026-09-03T17:00:00Z', false],
    ] as [$createdAt, $inactive]) {
        User::factory()->create(['created_at' => CarbonImmutable::parse($createdAt), 'is_inactive' => $inactive]);
    }
    $report = app(SystemInsightsReader::class)->usersStatistics($actor,
        CarbonImmutable::parse('2026-08-31T17:00:00Z'), CarbonImmutable::parse('2026-09-03T17:00:00Z'));

    expect($report['registrations'])->toMatchArray([
        'current' => 3, 'previous' => 2, 'delta' => 1, 'change_percent' => 50.0,
        'previous_start_utc' => '2026-08-28T17:00:00+00:00', 'previous_end_utc' => '2026-08-31T17:00:00+00:00',
    ])->and($report['period']['end_exclusive'])->toBeTrue()
        ->and($report['current_accounts'])->toMatchArray(['total' => 8, 'active' => 6, 'inactive' => 2])
        ->and($report['current_accounts']['definition'])->toContain('not login activity')
        ->and(json_encode($report))->not->toContain($actor->email, $actor->uuid, 'password', 'two_factor');
});

it('does not invent a percentage when the comparison period has no registrations', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-11T12:00:00Z'));
    $actor = systemInsightsActor();
    $report = app(SystemInsightsReader::class)->usersStatistics($actor,
        CarbonImmutable::parse('2026-09-11T00:00:00Z'), CarbonImmutable::parse('2026-09-12T00:00:00Z'));
    expect($report['registrations'])->toMatchArray(['current' => 1, 'previous' => 0, 'delta' => 1, 'change_percent' => null]);
});

it('compares complete calendar months with unequal lengths when explicit bounds are supplied', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10T12:00:00Z'));
    $actor = systemInsightsActor();
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-08-01T00:00:00Z')]);
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-08-31T23:59:59Z')]);
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-09-01T00:00:00Z')]);
    $reader = app(SystemInsightsReader::class);
    $start = CarbonImmutable::parse('2026-09-01T00:00:00Z');
    $end = CarbonImmutable::parse('2026-10-01T00:00:00Z');
    $default = $reader->usersStatistics($actor, $start, $end);
    $explicit = $reader->usersStatistics($actor, $start, $end, CarbonImmutable::parse('2026-08-01T00:00:00Z'), $start);

    expect($default['registrations']['previous'])->toBe(1)
        ->and($explicit['registrations'])->toMatchArray(['current' => 1, 'previous' => 2, 'delta' => -1, 'change_percent' => -50.0,
            'comparison' => 'explicit_period', 'previous_start_utc' => '2026-08-01T00:00:00+00:00']);
});

it('requires current report permission and denies inactive actors', function (string $report, string $permission): void {
    $actor = systemInsightsActor([$permission]);
    $reader = app(SystemInsightsReader::class);
    $reader->authorize($actor, $report);
    $actor->revokePermissionTo($permission);
    expect(fn () => $reader->authorize($actor, $report))->toThrow(AuthorizationException::class);
    $actor->givePermissionTo($permission);
    $actor->forceFill(['is_inactive' => true])->save();
    expect(fn () => $reader->authorize($actor, $report))->toThrow(AuthorizationException::class);
})->with([
    ['users_statistics', 'users.view'], ['queue_status', 'system.queue.view'], ['access_guide', 'panel.access'],
]);

it('rechecks permission within every reader entry point', function (): void {
    $actor = systemInsightsActor([]);
    $reader = app(SystemInsightsReader::class);
    expect(fn () => $reader->usersStatistics($actor, CarbonImmutable::now()->subDay(), CarbonImmutable::now()))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->queueStatus($actor))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->accessGuide($actor, 'en'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->authorize($actor, 'arbitrary_table'))->toThrow(AuthorizationException::class);
});

it('bounds trusted report intervals and guide locales', function (): void {
    $actor = systemInsightsActor();
    $reader = app(SystemInsightsReader::class);
    $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
    expect(fn () => $reader->usersStatistics($actor, $start, $start))->toThrow(ValidationException::class)
        ->and(fn () => $reader->usersStatistics($actor, $start, $start->addDays(368)))->toThrow(ValidationException::class)
        ->and(fn () => $reader->usersStatistics($actor, $start, $start->addDay(), $start->subDay()))->toThrow(ValidationException::class)
        ->and(fn () => $reader->usersStatistics($actor, $start, $start->addDay(), $start, $start->addDays(368)))->toThrow(ValidationException::class)
        ->and(fn () => $reader->accessGuide($actor, '../../.env'))->toThrow(ValidationException::class);
});

it('returns only queue aggregates and performs no job or failed-job writes', function (): void {
    $actor = systemInsightsActor();
    $this->travelTo(CarbonImmutable::parse('2026-09-11T12:00:00Z'));
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite', 'queue.failed.database' => 'sqlite']);
    $timestamp = now()->getTimestamp();
    foreach ([['default', null, $timestamp], ['other', null, $timestamp + 1], ['default', $timestamp - 60, $timestamp - 60]] as [$queue, $reserved, $available]) {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => 'private-job-payload-canary', 'attempts' => 0, 'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => $timestamp - 60]);
    }
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'secret-connection-name', 'queue' => 'secret-queue-name',
        'payload' => 'private-failed-payload-canary', 'exception' => 'private-stacktrace-canary', 'failed_at' => now()]);
    $beforeJobs = DB::table('jobs')->get()->toArray();
    $beforeFailed = DB::table('failed_jobs')->get()->toArray();
    $report = app(SystemInsightsReader::class)->queueStatus($actor);

    expect($report['jobs'])->toMatchArray(['status' => 'available', 'total' => 3, 'ready_unreserved' => 1, 'delayed_unreserved' => 1, 'reserved' => 1])
        ->and($report['failed_jobs'])->toMatchArray(['status' => 'available', 'total' => 1])
        ->and(json_encode($report))->not->toContain('private-', 'secret-', 'default', 'other')
        ->and(DB::table('jobs')->get()->toArray())->toEqual($beforeJobs)
        ->and(DB::table('failed_jobs')->get()->toArray())->toEqual($beforeFailed);
});

it('reports unsupported and unavailable storage without fabricated zero counts or exceptions', function (): void {
    $actor = systemInsightsActor();
    config(['queue.default' => 'redis', 'queue.failed.driver' => 'dynamodb']);
    $report = app(SystemInsightsReader::class)->queueStatus($actor);
    expect($report['jobs'])->toMatchArray(['status' => 'unsupported', 'total' => null])
        ->and($report['failed_jobs'])->toMatchArray(['status' => 'unsupported', 'total' => null]);

    config(['queue.default' => 'database', 'queue.connections.database.table' => 'missing_jobs_canary', 'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'sqlite', 'queue.failed.table' => 'missing_failures_canary']);
    $report = app(SystemInsightsReader::class)->queueStatus($actor);
    expect($report['jobs'])->toMatchArray(['status' => 'unavailable', 'total' => null])
        ->and($report['failed_jobs'])->toMatchArray(['status' => 'unavailable', 'total' => null])
        ->and(json_encode($report))->not->toContain('canary', 'SQLSTATE', 'select');
});

it('provides curated bilingual role instructions with no role mutation', function (): void {
    $actor = systemInsightsActor();
    $reader = app(SystemInsightsReader::class);
    $roles = Role::query()->count();
    $english = $reader->accessGuide($actor, 'en');
    $vietnamese = $reader->accessGuide($actor, 'vi');
    expect($english['locale'])->toBe('en')->and($vietnamese['locale'])->toBe('vi')
        ->and(array_column($english['topics'], 'key'))->toBe(array_column($vietnamese['topics'], 'key'))
        ->and(json_encode($english))->toContain('users.assign_roles', 'super_admin', 'roles.update', 'panel.access')
        ->and(Role::query()->count())->toBe($roles);
});

it('installs the explicit queue report permission without granting it to admin or staff', function (): void {
    $this->artisan('app:starter-kit-install', ['--force' => true])->assertSuccessful();
    expect(Permission::query()->where('name', 'system.queue.view')->exists())->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('system.queue.view'))->toBeFalse()
        ->and(Role::findByName('staff')->hasPermissionTo('system.queue.view'))->toBeFalse()
        ->and(Role::findByName('super_admin')->hasPermissionTo('system.queue.view'))->toBeTrue();
});
