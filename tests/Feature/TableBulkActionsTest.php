<?php

declare(strict_types=1);

use App\Filament\Actions\ChangeSelectedUserStatus;
use App\Filament\Actions\ExportSelectedRecords;
use App\Filament\Clusters\Account\Pages\Sessions;
use App\Filament\Resources\ActivityLogs\Pages\ManageActivityLogs;
use App\Filament\Resources\LoginSessions\Pages\ManageLoginSessions;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Tables\SelectableTable;
use App\Filament\Widgets\MobileDeviceSessions;
use App\Filament\Widgets\RecentLoginSessions;
use App\Filament\Widgets\RecentSecurityEvents;
use App\Models\LoginSession;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('adds selection and an authorized export to every core list without removing existing bulk actions', function (string $page): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $component = Livewire::actingAs($admin)->test($page)->assertTableBulkActionVisible('exportSelected');
    expect($component->instance()->getTable())->toBeInstanceOf(SelectableTable::class)
        ->and($component->instance()->getTable()->isSelectionEnabled())->toBeTrue();

    if ($page === ListRoles::class) {
        $component->assertTableBulkActionVisible('delete');
    }
    if ($page === ListUsers::class) {
        $component->assertSeeHtml('fi-ta-record-checkbox');
    }
    if ($page === ManageLoginSessions::class) {
        $component->assertTableBulkActionVisible('revokeSelected');
    }
    if ($page === ManageActivityLogs::class) {
        $component->assertTableBulkActionDoesNotExist('delete');
    }
})->with([ListUsers::class, ListRoles::class, ManageLoginSessions::class, ManageActivityLogs::class]);

it('hides export and checkboxes from a read-only user without export permission', function (): void {
    $user = User::factory()->create()->assignRole('admin');
    $user->roles()->detach();
    $user->givePermissionTo(['panel.access', 'users.view']);
    $component = Livewire::actingAs($user)->test(ListUsers::class)
        ->assertTableBulkActionHidden('exportSelected')
        ->assertTableBulkActionHidden('activateSelected')
        ->assertTableBulkActionHidden('deactivateSelected')
        ->assertDontSeeHtml('fi-ta-record-checkbox');

    expect($component->instance()->getTable()->isSelectionEnabled())->toBeFalse();
});

it('downloads only selected visible columns and excludes hidden secrets', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $selected = User::factory()->create(['email' => 'selected@example.test']);
    $unselected = User::factory()->create(['email' => 'unselected@example.test']);
    $component = Livewire::actingAs($admin)->test(ListUsers::class);
    $table = $component->instance()->getTable()->columns([
        TextColumn::make('email'),
        TextColumn::make('password'),
        TextColumn::make('uuid')->hidden(),
        TextColumn::make('two_factor_secret'),
        TextColumn::make('hidden_value')->state('not-exported')->toggleable(isToggledHiddenByDefault: true),
        TextColumn::make('nullable_number')->state(null)->formatStateUsing(fn (int $state): string => (string) $state)->placeholder('—'),
    ]);
    $response = ExportSelectedRecords::download($table, collect([$selected]));
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain($selected->email, '—')->not->toContain($unselected->email, $selected->password, 'two_factor_secret', 'uuid', 'hidden_value', 'not-exported')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('supports actual Livewire file downloads', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();
    Livewire::actingAs($admin)->test(ListUsers::class)
        ->callTableBulkAction('exportSelected', [$user])->assertFileDownloaded();
});

it('rechecks export authorization after the toolbar was rendered', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['panel.access', 'users.view', 'tables.export']);
    $component = Livewire::actingAs($user)->test(ListUsers::class)->assertTableBulkActionVisible('exportSelected');
    $user->revokePermissionTo('tables.export');
    $this->actingAs($user->fresh());

    expect(fn () => ExportSelectedRecords::download($component->instance()->getTable(), collect([$user])))
        ->toThrow(HttpException::class);
});

it('rejects selected records outside the filtered query', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $allowed = User::factory()->create(['email' => 'allowed@example.test']);
    $outside = User::factory()->create(['email' => 'outside@example.test']);
    $component = Livewire::actingAs($admin)->test(ListUsers::class)->searchTable('allowed@example.test');

    expect(fn () => ExportSelectedRecords::download($component->instance()->getTable(), collect([$outside])))
        ->toThrow(HttpException::class);
});

it('neutralizes spreadsheet formulas without changing ordinary text', function (string $value, string $expected): void {
    expect(ExportSelectedRecords::cell($value))->toBe($expected);
})->with([
    ['=HYPERLINK("https://example.test")', '\'=HYPERLINK("https://example.test")'],
    ["\t+1", "'\t+1"],
    ['@SUM(1,1)', '\'@SUM(1,1)'],
    ['<strong>Safe &amp; readable</strong>', 'Safe & readable'],
]);

it('changes selected accounts and revokes their sessions and tokens on deactivation', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $first = User::factory()->create();
    $second = User::factory()->create();
    $untouched = User::factory()->create();
    $token = $first->createToken('bulk-status-test')->accessToken;
    $session = LoginSession::query()->create([
        'id' => 'bulk-status-browser-session', 'user_uuid' => $first->getKey(), 'last_active_at' => now(),
    ]);
    Livewire::actingAs($admin)->test(ListUsers::class)
        ->callTableBulkAction('deactivateSelected', [$first, $second])->assertHasNoErrors();

    expect($first->fresh()->is_inactive)->toBeTrue()->and($second->fresh()->is_inactive)->toBeTrue()
        ->and($untouched->fresh()->is_inactive)->toBeFalse()
        ->and($session->fresh()->revoked_at)->not->toBeNull()
        ->and($token->fresh())->toBeNull();

    Livewire::actingAs($admin)->test(ListUsers::class)
        ->callTableBulkAction('activateSelected', [$first, $second])->assertHasNoErrors();
    expect($first->fresh()->is_inactive)->toBeFalse()->and($second->fresh()->is_inactive)->toBeFalse();
});

it('rejects self deactivation atomically', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $other = User::factory()->create();
    $this->actingAs($admin);
    expect(fn () => ChangeSelectedUserStatus::change(collect([$other, $admin]), true))->toThrow(ValidationException::class);
    expect($other->fresh()->is_inactive)->toBeFalse()->and($admin->fresh()->is_inactive)->toBeFalse();
});

it('rejects deactivating all remaining super administrators atomically', function (): void {
    $operator = User::factory()->create()->assignRole('admin');
    $first = User::factory()->create()->assignRole('super_admin');
    $second = User::factory()->create()->assignRole('super_admin');
    $this->actingAs($operator);
    try {
        ChangeSelectedUserStatus::change(collect([$first, $second]), true);
        $this->fail('The final super administrators must be protected.');
    } catch (ValidationException|HttpException) {
        // Some hosts additionally deny admins all updates to super administrators.
    }
    expect($first->fresh()->is_inactive)->toBeFalse()->and($second->fresh()->is_inactive)->toBeFalse();
});

it('hides mutations and rejects execution without the matching update permission', function (): void {
    $reader = User::factory()->create();
    $reader->givePermissionTo(['panel.access', 'users.view', 'tables.export']);
    $other = User::factory()->create();
    Livewire::actingAs($reader)->test(ListUsers::class)->assertTableBulkActionHidden('deactivateSelected');
    expect(fn () => ChangeSelectedUserStatus::change(collect([$other]), true))
        ->toThrow(HttpException::class);
    expect($other->fresh()->is_inactive)->toBeFalse();
});

it('deletes only custom roles and protects all system roles in a bulk selection', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $custom = Role::findOrCreate('bulk-custom-role', 'web');
    $system = Role::findByName('admin', 'web');
    Livewire::actingAs($admin)->test(ListRoles::class)->callTableBulkAction('delete', [$custom, $system]);
    expect($custom->fresh())->toBeNull()->and($system->fresh())->not->toBeNull();
});

it('hides role deletion even from a super administrator without delete permission', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $role = Role::findByName('super_admin', 'web');
    $role->revokePermissionTo('roles.delete');
    Livewire::actingAs($admin)->test(ListRoles::class)->assertTableBulkActionHidden('delete');
});

it('exports opaque session selections without exposing raw browser session IDs', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $session = LoginSession::query()->create([
        'id' => 'private-browser-session-identifier', 'user_uuid' => $admin->getKey(), 'last_active_at' => now(),
    ]);
    $component = Livewire::actingAs($admin)->test(ManageLoginSessions::class);
    $key = $component->instance()->getTableRecordKey($session);
    $component->callTableBulkAction('exportSelected', [$key])->assertFileDownloaded();
    expect($key)->toStartWith('douwyn_ls_');
});

it('supports selection on authorized dashboard tables', function (string $widget): void {
    $admin = User::factory()->create()->assignRole('admin');
    Livewire::actingAs($admin)->test($widget)->assertTableBulkActionVisible('exportSelected');
})->with([RecentLoginSessions::class, RecentSecurityEvents::class, MobileDeviceSessions::class]);

it('exports opaque session keys correctly from widgets and the account page', function (string $page): void {
    $admin = User::factory()->create()->assignRole('admin');
    $session = LoginSession::query()->create([
        'id' => 'private-session-key-for-selection', 'user_uuid' => $admin->getKey(), 'last_active_at' => now(),
    ]);
    $component = Livewire::actingAs($admin)->test($page);
    $keys = $component->instance()->getAllSelectableTableRecordKeys();
    expect($keys)->toHaveCount(1)->and($keys[0])->toStartWith('douwyn_ls_');
    $component->callTableBulkAction('exportSelected', [$keys[0]])->assertFileDownloaded();
    expect($component->instance()->selectedTableRecords)->not->toContain($session->getKey());
})->with(['dashboard' => [RecentLoginSessions::class], 'account' => [Sessions::class]]);

it('denies an inactive account access to export even if it retains permissions', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $component = Livewire::actingAs($admin)->test(ListUsers::class);
    $admin->update(['is_inactive' => true]);
    $this->actingAs($admin->fresh());
    expect(ExportSelectedRecords::canExport($component->instance()->getTable()))->toBeFalse();
});

it('revokes selected sessions while protecting the current and expired sessions', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $current = LoginSession::query()->create([
        'id' => 'current-private-session', 'user_uuid' => $admin->getKey(), 'last_active_at' => now(),
    ]);
    $other = LoginSession::query()->create([
        'id' => 'other-private-session', 'user_uuid' => $admin->getKey(), 'last_active_at' => now(),
    ]);
    $expired = LoginSession::query()->create([
        'id' => 'expired-private-session', 'user_uuid' => $admin->getKey(), 'last_active_at' => now()->subDays(2),
    ]);
    session()->put('login_session_registry_id', $current->getKey());
    $component = Livewire::actingAs($admin)->test(ManageLoginSessions::class);
    $keys = collect([$current, $other, $expired])->map(fn ($record): string => $component->instance()->getTableRecordKey($record))->all();
    $component->callTableBulkAction('revokeSelected', $keys)->assertHasNoErrors();
    expect($current->fresh()->revoked_at)->toBeNull()->and($expired->fresh()->revoked_at)->toBeNull()
        ->and($other->fresh()->revoked_at)->not->toBeNull();
});

it('does not show revoke bulk action without session revoke permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['panel.access', 'tables.export', 'login_sessions.view']);
    Livewire::actingAs($user)->test(ManageLoginSessions::class)
        ->assertTableBulkActionVisible('exportSelected')->assertTableBulkActionHidden('revokeSelected');
});

it('protects system roles even when a legacy gate grants all delete abilities', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, ['delete', 'deleteAny'], true) ? true : null);
    $system = Role::findByName('admin', 'web');
    Livewire::actingAs($admin)->test(ListRoles::class)->callTableBulkAction('delete', [$system]);
    expect($system->fresh())->not->toBeNull();
});
