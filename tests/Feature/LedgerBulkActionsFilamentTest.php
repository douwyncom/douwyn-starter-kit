<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\User;
use Composer\Autoload\ClassLoader;
use Douwyn\StarterKit\Modules\Ledger\Enums\AccountStatus;
use Douwyn\StarterKit\Modules\Ledger\Enums\AssetKind;
use Douwyn\StarterKit\Modules\Ledger\Enums\JournalType;
use Douwyn\StarterKit\Modules\Ledger\Enums\WalletKind;
use Douwyn\StarterKit\Modules\Ledger\Filament\Resources\Accounts\Tables\AccountsTable;
use Douwyn\StarterKit\Modules\Ledger\Filament\Resources\Assets\Tables\AssetsTable;
use Douwyn\StarterKit\Modules\Ledger\Filament\Resources\Journals\Tables\JournalsTable;
use Douwyn\StarterKit\Modules\Ledger\Filament\Resources\UserLedgers\Tables\UserLedgersTable;
use Douwyn\StarterKit\Modules\Ledger\LedgerServiceProvider;
use Douwyn\StarterKit\Modules\Ledger\Models\Account;
use Douwyn\StarterKit\Modules\Ledger\Models\Asset;
use Douwyn\StarterKit\Modules\Ledger\Models\Journal;
use Douwyn\StarterKit\Modules\Ledger\Models\Wallet;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

/** @var ClassLoader $ledgerBulkActionsLoader */
$ledgerBulkActionsLoader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$ledgerBulkActionsLoader->addPsr4(
    'Douwyn\\StarterKit\\Modules\\Ledger\\',
    dirname(__DIR__, 2).'/modules/starter-kit-ledger/src/',
    true,
);

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! is_file(base_path('modules/starter-kit-ledger/composer.json'))) {
        $this->markTestSkipped('The commercial Ledger module is not present in this host checkout.');
    }

    config([
        'starter-kit-ledger.connection' => null,
        'starter-kit-ledger.database.mode' => 'host',
        'starter-kit-ledger.database.connection' => null,
        'starter-kit-ledger.locks.driver' => 'cache',
    ]);

    $this->app->register(LedgerServiceProvider::class);
    (require base_path('modules/starter-kit-ledger/database/migrations/2026_07_22_000001_create_ledger_tables.php'))->up();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach ([
        'ledger_accounts.view',
        'ledger_accounts.freeze',
        'ledger_accounts.freeze_bulk',
        'ledger_assets.manage',
        'ledger_assets.manage_bulk',
        'ledger_transactions.view',
        'ledger_adjustments.create',
        'ledger_adjustments.reverse_bulk',
        'ledger_users.provision',
        'ledger_users.provision_bulk',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

it('hides each ledger bulk action unless both its business and bulk permissions are granted', function (
    string $table,
    string $action,
    array $businessPermissions,
    string $bulkPermission,
): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    Livewire::test(LedgerBulkActionsTable::class, ['ledgerTable' => $table])
        ->assertTableBulkActionHidden($action);

    $actor->givePermissionTo($bulkPermission);
    Livewire::test(LedgerBulkActionsTable::class, ['ledgerTable' => $table])
        ->assertTableBulkActionHidden($action);

    $actor->revokePermissionTo($bulkPermission);
    $actor->givePermissionTo($businessPermissions);
    Livewire::test(LedgerBulkActionsTable::class, ['ledgerTable' => $table])
        ->assertTableBulkActionHidden($action);

    $actor->givePermissionTo($bulkPermission);
    Livewire::test(LedgerBulkActionsTable::class, ['ledgerTable' => $table])
        ->assertTableBulkActionVisible($action);
})->with('ledger bulk action authorization');

it('rejects a mounted ledger bulk action when any required permission is revoked before execution', function (
    string $table,
    string $action,
    array $businessPermissions,
    string $bulkPermission,
    string $revokedPermission,
): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo([...$businessPermissions, $bulkPermission]);
    $this->actingAs($actor);

    [$record, $data, $unchanged] = ledgerBulkActionFixture($table);

    $component = Livewire::test(LedgerBulkActionsTable::class, ['ledgerTable' => $table])
        ->assertTableBulkActionVisible($action)
        ->mountTableBulkAction($action, [$record])
        ->assertTableBulkActionMounted($action);

    $component->setTableBulkActionData($data);
    $actor->revokePermissionTo($revokedPermission);
    expect($component->instance()->getTable()->getBulkAction($action)?->isVisible())->toBeFalse();
    $component->callMountedTableBulkAction()->assertTableBulkActionHalted($action);

    $unchanged();
})->with('ledger bulk action revocation');

dataset('ledger bulk action authorization', [
    'freeze accounts' => [
        'accounts',
        'freezeSelected',
        ['ledger_accounts.view', 'ledger_accounts.freeze'],
        'ledger_accounts.freeze_bulk',
    ],
    'unfreeze accounts' => [
        'accounts',
        'unfreezeSelected',
        ['ledger_accounts.view', 'ledger_accounts.freeze'],
        'ledger_accounts.freeze_bulk',
    ],
    'enable assets' => [
        'assets',
        'enableSelected',
        ['ledger_assets.manage'],
        'ledger_assets.manage_bulk',
    ],
    'disable assets' => [
        'assets',
        'disableSelected',
        ['ledger_assets.manage'],
        'ledger_assets.manage_bulk',
    ],
    'journals' => [
        'journals',
        'reverseSelected',
        ['ledger_transactions.view', 'ledger_adjustments.create'],
        'ledger_adjustments.reverse_bulk',
    ],
    'user ledgers' => [
        'user-ledgers',
        'addAssetsToSelectedLedgers',
        ['ledger_accounts.view', 'ledger_users.provision'],
        'ledger_users.provision_bulk',
    ],
]);

dataset('ledger bulk action revocation', [
    'account business permission' => [
        'accounts',
        'freezeSelected',
        ['ledger_accounts.view', 'ledger_accounts.freeze'],
        'ledger_accounts.freeze_bulk',
        'ledger_accounts.freeze',
    ],
    'account bulk permission' => [
        'accounts',
        'freezeSelected',
        ['ledger_accounts.view', 'ledger_accounts.freeze'],
        'ledger_accounts.freeze_bulk',
        'ledger_accounts.freeze_bulk',
    ],
    'asset business permission' => [
        'assets',
        'disableSelected',
        ['ledger_assets.manage'],
        'ledger_assets.manage_bulk',
        'ledger_assets.manage',
    ],
    'asset bulk permission' => [
        'assets',
        'disableSelected',
        ['ledger_assets.manage'],
        'ledger_assets.manage_bulk',
        'ledger_assets.manage_bulk',
    ],
    'journal business permission' => [
        'journals',
        'reverseSelected',
        ['ledger_transactions.view', 'ledger_adjustments.create'],
        'ledger_adjustments.reverse_bulk',
        'ledger_adjustments.create',
    ],
    'journal bulk permission' => [
        'journals',
        'reverseSelected',
        ['ledger_transactions.view', 'ledger_adjustments.create'],
        'ledger_adjustments.reverse_bulk',
        'ledger_adjustments.reverse_bulk',
    ],
    'user ledger business permission' => [
        'user-ledgers',
        'addAssetsToSelectedLedgers',
        ['ledger_accounts.view', 'ledger_users.provision'],
        'ledger_users.provision_bulk',
        'ledger_users.provision',
    ],
    'user ledger bulk permission' => [
        'user-ledgers',
        'addAssetsToSelectedLedgers',
        ['ledger_accounts.view', 'ledger_users.provision'],
        'ledger_users.provision_bulk',
        'ledger_users.provision_bulk',
    ],
]);

/**
 * @return array{Model, array<string, mixed>, Closure(): void}
 */
function ledgerBulkActionFixture(string $table): array
{
    $network = strtolower(str_replace('-', '', (string) Uuid::uuid7()));
    $asset = Asset::query()->create([
        'key' => 'TST:'.$network,
        'code' => 'TST',
        'name' => 'Bulk authorization test asset',
        'kind' => AssetKind::Crypto,
        'decimals' => 2,
        'network' => $network,
        'is_enabled' => true,
    ]);

    if ($table === 'assets') {
        return [
            $asset,
            ['reason' => 'Permission revocation integration test.'],
            static function () use ($asset): void {
                expect($asset->fresh()->is_enabled)->toBeTrue();
            },
        ];
    }

    if ($table === 'journals') {
        $journal = Journal::query()->create([
            'source' => 'test.bulk-authorization',
            'idempotency_key' => (string) Uuid::uuid7(),
            'request_hash' => hash('sha256', (string) Uuid::uuid7()),
            'type' => JournalType::Credit,
            'posted_at' => now(),
        ]);

        return [
            $journal,
            ['reason' => 'Permission revocation integration test.'],
            static function () use ($journal): void {
                expect(Journal::query()->where('reversal_of_uuid', $journal->getKey())->count())->toBe(0);
            },
        ];
    }

    if ($table === 'user-ledgers') {
        $owner = User::factory()->create();
        $wallet = Wallet::query()->create([
            'kind' => WalletKind::User,
            'owner_type' => $owner->getMorphClass(),
            'owner_uuid' => (string) $owner->getKey(),
            'user_uuid' => (string) $owner->getKey(),
            'label' => 'Bulk authorization test wallet',
            'is_enabled' => true,
        ]);

        return [
            $wallet,
            [
                'asset_uuids' => [(string) $asset->getKey()],
                'reason' => 'Permission revocation integration test.',
            ],
            static function () use ($wallet): void {
                expect($wallet->accounts()->count())->toBe(0);
            },
        ];
    }

    $wallet = Wallet::query()->create([
        'kind' => WalletKind::System,
        'owner_type' => 'test.system',
        'owner_uuid' => (string) Uuid::uuid7(),
        'label' => 'Bulk authorization test wallet',
        'is_enabled' => true,
    ]);
    $account = Account::query()->create([
        'wallet_uuid' => (string) $wallet->getKey(),
        'asset_uuid' => (string) $asset->getKey(),
        'available_atoms' => '0',
        'held_atoms' => '0',
        'status' => AccountStatus::Active,
        'version' => 0,
    ]);

    return [
        $account,
        ['reason' => 'Permission revocation integration test.'],
        static function () use ($account): void {
            expect($account->fresh()->status)->toBe(AccountStatus::Active);
        },
    ];
}

final class LedgerBulkActionsTable extends TableComponent
{
    public string $ledgerTable;

    public function mount(string $ledgerTable): void
    {
        $this->ledgerTable = $ledgerTable;
    }

    public function table(Table $table): Table
    {
        return match ($this->ledgerTable) {
            'accounts' => AccountsTable::configure($table->query(Account::query())),
            'assets' => AssetsTable::configure($table->query(Asset::query())),
            'journals' => JournalsTable::configure($table->query(Journal::query())),
            'user-ledgers' => UserLedgersTable::configure($table->query(
                Wallet::query()
                    ->where('kind', WalletKind::User)
                    ->whereNotNull('user_uuid')
                    ->withCount('accounts'),
            )),
        };
    }

    public function render(): View
    {
        return view()->file(__DIR__.'/../Fixtures/ledger-bulk-actions-table.blade.php');
    }
}
