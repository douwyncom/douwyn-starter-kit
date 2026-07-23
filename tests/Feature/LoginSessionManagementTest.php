<?php

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Enums\SecurityEvent;
use App\Enums\TokenRevokeReason;
use App\Filament\Clusters\Account\Pages\Sessions as AccountSessions;
use App\Filament\Resources\LoginSessions\LoginSessionResource;
use App\Filament\Resources\LoginSessions\Pages\ManageLoginSessions;
use App\Filament\Widgets\RecentLoginSessions;
use App\Models\LoginSession;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\LoginSessionIdentifier;
use App\Services\Auth\MobileTokenService;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

function managedBrowserSession(
    User $user,
    string $id,
    ?DateTimeInterface $lastActiveAt = null,
    ?DateTimeInterface $revokedAt = null,
): LoginSession {
    return LoginSession::query()->create([
        'id' => $id,
        'user_uuid' => $user->getKey(),
        'ip_address' => '203.0.113.25',
        'user_agent' => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/140.0',
        'last_active_at' => $lastActiveAt ?? now(),
        'revoked_at' => $revokedAt,
    ]);
}

it('restricts the global login session resource with dedicated permissions', function () {
    $operator = User::factory()->create();
    $operator->assignRole('admin');
    Role::findByName('admin')->revokePermissionTo('login_sessions.view');

    $this->actingAs($operator)
        ->get(LoginSessionResource::getUrl())
        ->assertForbidden();
});

it('lets a view-only operator inspect sessions without revoking them', function () {
    $operator = User::factory()->create();
    $operator->assignRole('admin');
    Role::findByName('admin')->revokePermissionTo('login_sessions.revoke');
    $target = User::factory()->create();
    $session = managedBrowserSession($target, 'raw-view-only-session-secret');

    $this->actingAs($operator)
        ->get(LoginSessionResource::getUrl())
        ->assertOk();

    Livewire::test(ManageLoginSessions::class)
        ->assertCanSeeTableRecords([$session])
        ->assertTableActionHidden('revoke', $session)
        ->assertTableBulkActionHidden('revokeSelected');

    Livewire::test(RecentLoginSessions::class)
        ->assertCanSeeTableRecords([$session])
        ->assertTableActionHidden('revoke', $session);

    expect($session->fresh()->revoked_at)->toBeNull();
});

it('lists and filters every browser login session without exposing raw session ids', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();
    $rawActiveId = 'raw-active-session-'.Str::random(32);
    $active = managedBrowserSession($target, $rawActiveId);
    $expired = managedBrowserSession(
        $target,
        'raw-expired-session-'.Str::random(32),
        now()->subMinutes((int) config('session.lifetime', 120) + 5),
    );
    $revoked = managedBrowserSession(
        $target,
        'raw-revoked-session-'.Str::random(32),
        now()->subMinute(),
        now(),
    );

    $this->actingAs($admin);

    $component = Livewire::test(ManageLoginSessions::class)
        ->assertCanSeeTableRecords([$active, $expired, $revoked])
        ->searchTable('chrome')
        ->assertCanSeeTableRecords([$active, $expired, $revoked])
        ->searchTable()
        ->assertDontSee($rawActiveId)
        ->assertSee(app(LoginSessionIdentifier::class)->forSession($active), escape: false)
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$expired, $revoked])
        ->resetTableFilters()
        ->filterTable('status', 'expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$active, $revoked])
        ->resetTableFilters()
        ->filterTable('status', 'revoked')
        ->assertCanSeeTableRecords([$revoked])
        ->assertCanNotSeeTableRecords([$active, $expired]);

    expect($component->instance()->getTableRecordKey($active))
        ->toBe(app(LoginSessionIdentifier::class)->forSession($active))
        ->and($component->instance()->getTable()->isStackedOnMobile())->toBeTrue()
        ->and($component->instance()->getTable()->selectsCurrentPageOnly())->toBeTrue()
        ->and($component->instance()->getTable()->getFilter('user')?->isPreloaded())->toBeFalse();
});

it('persists non-null public identifiers for every login session', function () {
    $user = User::factory()->create();
    $session = managedBrowserSession($user, 'raw-public-id-'.Str::random(32));
    $column = collect(Schema::getColumns('login_sessions'))
        ->firstWhere('name', 'public_id_hash');

    expect($session->public_id_hash)->toMatch('/^[a-f0-9]{64}$/D')
        ->and($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse()
        ->and(app(LoginSessionIdentifier::class)->resolve(
            app(LoginSessionIdentifier::class)->forSession($session),
        )?->is($session))->toBeTrue();
});

it('applies the user deep link filter and rejects raw or out-of-scope table keys', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();
    $other = User::factory()->create();
    $targetSession = managedBrowserSession($target, 'raw-filtered-session-'.Str::random(32));
    $otherSession = managedBrowserSession($other, 'raw-other-session-'.Str::random(32));
    $identifiers = app(LoginSessionIdentifier::class);

    $this->actingAs($admin);

    $url = LoginSessionResource::getUrl('index', [
        'filters' => ['user' => ['value' => $target->getKey()]],
    ]);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $global = Livewire::withQueryParams($query)
        ->test(ManageLoginSessions::class)
        ->assertCanSeeTableRecords([$targetSession])
        ->assertCanNotSeeTableRecords([$otherSession]);

    $account = Livewire::test(AccountSessions::class);

    expect(data_get($query, 'filters.user.value'))->toBe($target->getKey())
        ->and($global->instance()->getTableRecord((string) $targetSession->getKey()))->toBeNull()
        ->and($account->instance()->getTableRecord($identifiers->forSession($targetSession)))->toBeNull();
});

it('keeps raw session ids out of the account page and dashboard widget', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();
    $ownedRawId = 'raw-account-session-'.Str::random(32);
    $targetRawId = 'raw-dashboard-session-'.Str::random(32);
    $owned = managedBrowserSession($admin, $ownedRawId);
    $targetSession = managedBrowserSession($target, $targetRawId);

    $this->actingAs($admin);

    Livewire::test(AccountSessions::class)
        ->assertCanSeeTableRecords([$owned])
        ->assertDontSee($ownedRawId)
        ->assertSee(app(LoginSessionIdentifier::class)->forSession($owned), escape: false);

    Livewire::test(RecentLoginSessions::class)
        ->assertCanSeeTableRecords([$owned, $targetSession])
        ->assertDontSee([$ownedRawId, $targetRawId])
        ->assertSee(app(LoginSessionIdentifier::class)->forSession($targetSession), escape: false);
});

it('revokes a target browser session with admin telemetry but preserves mobile access', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();
    $rememberToken = $target->remember_token;
    $session = managedBrowserSession($target, 'raw-target-session-'.Str::random(32));
    $deviceId = (string) Str::uuid();
    $mobile = app(MobileTokenService::class)->issue($target, new MobileDeviceData(
        deviceName: 'Target iPhone',
        deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
        platform: DevicePlatform::IOS,
        appVersion: '4.0.0',
        ipAddress: '203.0.113.80',
        userAgent: 'DouwynMobile/4.0 iOS',
    ));

    $this->actingAs($admin);

    Livewire::test(ManageLoginSessions::class)
        ->assertTableActionVisible('revoke', $session)
        ->callTableAction('revoke', app(LoginSessionIdentifier::class)->forSession($session))
        ->assertNotified(__('resources/login_session.messages.revoked'));

    expect($session->fresh()->revoked_at)->not->toBeNull()
        ->and($target->fresh()->remember_token)->not->toBe($rememberToken)
        ->and($mobile->deviceSession->fresh()->revoked_at)->toBeNull()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::BROWSER_SESSION_REVOKED->value)
            ->where('subject_id', $target->getKey())
            ->where('causer_id', $admin->getKey())
            ->where('properties->channel', 'filament')
            ->where('properties->scope', 'single_session')
            ->where('properties->reason', TokenRevokeReason::ADMIN_REVOKED->value)
            ->exists())->toBeTrue();
});

it('revokes a stale session instance only once', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();
    $session = managedBrowserSession($target, 'raw-stale-session-'.Str::random(32));
    $firstInstance = LoginSession::query()->findOrFail($session->getKey());
    $staleInstance = LoginSession::query()->findOrFail($session->getKey());

    $this->actingAs($admin);

    $firstInstance->revoke(TokenRevokeReason::ADMIN_REVOKED->value);
    $staleInstance->revoke(TokenRevokeReason::ADMIN_REVOKED->value);

    expect($session->fresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::BROWSER_SESSION_REVOKED->value)
            ->where('subject_id', $target->getKey())
            ->count())->toBe(1);
});

it('protects the current Filament session and bulk revokes other selected sessions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $target = User::factory()->create();

    $this->actingAs($admin);
    $currentSessionId = session()->getId();
    session()->put('login_session_registry_id', $currentSessionId);

    $current = managedBrowserSession($admin, $currentSessionId);
    $first = managedBrowserSession($target, 'raw-bulk-one-'.Str::random(32));
    $second = managedBrowserSession($target, 'raw-bulk-two-'.Str::random(32));

    $component = Livewire::test(ManageLoginSessions::class)
        ->assertTableActionHidden('revoke', $current)
        ->assertTableBulkActionVisible('revokeSelected');

    $selectableKeys = $component->instance()->getAllSelectableTableRecordKeys();
    $identifiers = app(LoginSessionIdentifier::class);

    expect($selectableKeys)->toContain(
        $identifiers->forSession($first),
        $identifiers->forSession($second),
    )->not->toContain(
        (string) $current->getKey(),
        $identifiers->forSession($current),
        (string) $first->getKey(),
        (string) $second->getKey(),
    );

    $component->callTableBulkAction('revokeSelected', [$first, $second]);

    expect($current->fresh()->revoked_at)->toBeNull()
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->fresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::BROWSER_SESSION_REVOKED->value)
            ->where('properties->scope', 'selected_sessions')
            ->count())->toBe(2);
});
