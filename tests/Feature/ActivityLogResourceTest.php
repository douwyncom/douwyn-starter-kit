<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ManageActivityLogs;
use App\Models\Permission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('does not provision permission to delete audit records', function (): void {
    expect(Permission::query()->where('name', 'activity_logs.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'activity_logs.delete')->exists())->toBeFalse();
});

it('keeps activity logs immutable even with a legacy delete grant', function (): void {
    $administrator = User::factory()->create()->assignRole('super_admin');
    $legacyDeletePermission = Permission::findOrCreate('activity_logs.delete', 'web');
    $administrator->givePermissionTo($legacyDeletePermission);
    $activity = Activity::query()->create([
        'log_name' => 'security',
        'description' => 'security.login_succeeded',
        'event' => 'login_succeeded',
        'properties' => [],
    ]);

    $this->actingAs($administrator);

    expect(ActivityLogResource::getAuthorizationResponse('viewAny')->allowed())->toBeTrue()
        ->and(ActivityLogResource::getAuthorizationResponse('view', $activity)->allowed())->toBeTrue();

    foreach (['create', 'reorder', 'deleteAny', 'forceDeleteAny', 'restoreAny'] as $ability) {
        expect(ActivityLogResource::getAuthorizationResponse($ability)->denied())->toBeTrue();
    }

    foreach (['update', 'delete', 'forceDelete', 'restore', 'replicate'] as $ability) {
        expect(ActivityLogResource::getAuthorizationResponse($ability, $activity)->denied())->toBeTrue();
    }

    Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, ['delete', 'deleteAny'], true) ? true : null);

    expect(Gate::forUser($administrator)->allows('delete', $activity))->toBeTrue()
        ->and(ActivityLogResource::canDelete($activity))->toBeFalse()
        ->and(ActivityLogResource::canDeleteAny())->toBeFalse();

    Livewire::actingAs($administrator)
        ->test(ManageActivityLogs::class)
        ->assertTableActionDoesNotExist('delete', null, $activity)
        ->assertTableBulkActionDoesNotExist('delete');
});

it('shows exact log and UUID references while safely rendering nested properties', function (): void {
    $administrator = User::factory()->create()->assignRole('super_admin');
    $subject = User::factory()->create();
    $rawSecret = 'must-not-leak-from-the-activity-view';
    $properties = [
        'attributes' => [
            'profile' => [
                'first_name' => $rawSecret,
            ],
            'user_uuid' => $subject->getKey(),
        ],
        'old' => [
            'phone' => $rawSecret,
            'status' => 'pending',
        ],
    ];
    $activity = Activity::query()->create([
        'log_name' => 'default',
        'description' => 'updated',
        'event' => 'updated',
        'subject_type' => User::class,
        'subject_id' => $subject->getKey(),
        'causer_type' => User::class,
        'causer_id' => $administrator->getKey(),
        'properties' => $properties,
    ]);
    $expectedProperties = json_encode([
        'attributes' => [
            'profile' => [
                'first_name' => '[REDACTED]',
            ],
            'user_uuid' => $subject->getKey(),
        ],
        'old' => [
            'phone' => '[REDACTED]',
            'status' => 'pending',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    expect($activity->getKey())->toBeInt()
        ->and($activity->subject_id)->toBe($subject->getKey())
        ->and($activity->causer_id)->toBe($administrator->getKey());

    $component = Livewire::actingAs($administrator)
        ->test(ManageActivityLogs::class)
        ->assertTableActionExists('view', null, $activity)
        ->mountTableAction('view', $activity)
        ->assertHasNoErrors()
        ->assertTableActionDataSet([
            'id' => (string) $activity->getKey(),
            'subject_id' => $subject->getKey(),
            'causer_id' => $administrator->getKey(),
            'properties' => $expectedProperties,
        ]);

    expect(json_encode($component->snapshot, JSON_THROW_ON_ERROR))->not->toContain($rawSecret)
        ->and($component->html())->not->toContain($rawSecret);
});

it('prevents users without view permission from opening activity logs', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(ActivityLogResource::canAccess())->toBeFalse()
        ->and(ActivityLogResource::getAuthorizationResponse('viewAny')->denied())->toBeTrue();

    Livewire::actingAs($user)
        ->test(ManageActivityLogs::class)
        ->assertForbidden();
});
