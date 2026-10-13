<?php

declare(strict_types=1);

use App\Filament\Actions\ExportSelectedRecords;
use App\Filament\Tables\SelectableTable;
use App\Models\Permission;
use App\Models\User;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Douwyn\StarterKit\Modules\Telegram\Models\Bot;
use Douwyn\StarterKit\Modules\Telegram\Models\Contact;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps the shared bulk action in every commercial module resource table', function (): void {
    $installedModules = array_filter(InstalledVersions::getInstalledPackages(), fn (string $package): bool => str_starts_with($package, 'douwyncom/starter-kit-') && $package !== 'douwyncom/starter-kit-platform');
    if ($installedModules === [] || glob(base_path('modules/starter-kit-*/composer.json')) === []) {
        $this->markTestSkipped('Commercial modules are not part of the public starter-kit checkout.');
    }
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    auth()->logout();
    /** @var ClassLoader $loader */
    $loader = require base_path('vendor/autoload.php');
    $checked = [];

    foreach (glob(base_path('modules/starter-kit-*/composer.json')) as $manifestPath) {
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['autoload']['psr-4'] ?? [] as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $loader->addPsr4($prefix, dirname($manifestPath).'/'.$path, true);
            }
        }
    }

    // HRM builds organization filter options eagerly, even on an empty list.
    config(['starter-kit-hrm.connection' => 'sqlite']);
    if (is_file(base_path('modules/starter-kit-hrm/database/migrations/2026_07_28_000001_create_hrm_people_tables.php')) && ! Schema::hasTable('hrm_organizations')) {
        (require base_path('modules/starter-kit-hrm/database/migrations/2026_07_28_000001_create_hrm_people_tables.php'))->up();
    }

    foreach (glob(base_path('modules/starter-kit-*/src/Filament/Resources')) as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Resource.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (! preg_match('/namespace\s+([^;]+);/', $contents, $namespace)
                || ! preg_match('/(?:final\s+)?class\s+(\w+)\s+extends/', $contents, $class)) {
                continue;
            }
            $resource = $namespace[1].'\\'.$class[1];
            if (! is_subclass_of($resource, Resource::class) || (new ReflectionClass($resource))->isAbstract()) {
                continue;
            }
            $index = $resource::getPages()['index'] ?? null;
            if (! $index || ! is_subclass_of($index->getPage(), ListRecords::class)) {
                continue;
            }
            $page = new ($index->getPage());
            $model = $resource::getModel();
            $table = $resource::table(Table::make($page)->query($model::query()));

            expect($table)->toBeInstanceOf(SelectableTable::class, $resource)
                ->and($table->hasBulkAction('exportSelected'))->toBeTrue($resource)
                ->and($table->getBulkAction('exportSelected')->isVisible())->toBeFalse($resource);
            $checked[] = $resource;
        }
    }

    expect(count($checked))->toBeGreaterThan(0);
});

it('requires both export and module read permissions for the module toolbar', function (string $resource, string $permission): void {
    if (! class_exists($resource) || Gate::getPolicyFor($resource::getModel()) === null) {
        $this->markTestSkipped('This module is not installed in the host.');
    }
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Permission::findOrCreate($permission, 'web');
    $user = User::factory()->create();
    $user->givePermissionTo(['panel.access', 'tables.export']);
    $this->actingAs($user);
    $page = new ($resource::getPages()['index']->getPage());
    $model = $resource::getModel();
    $table = $resource::table(Table::make($page)->query($model::query()));
    expect($table->getBulkAction('exportSelected')->isVisible())->toBeFalse();

    $user->givePermissionTo($permission);
    // An existing action caches visibility; authorization must be evaluated afresh.
    expect(ExportSelectedRecords::canExport($table))->toBeTrue();
    $record = new $model;
    expect(ExportSelectedRecords::canRead($table, $record))->toBeTrue();

    $user->revokePermissionTo('tables.export');
    expect(ExportSelectedRecords::canExport($table))->toBeFalse();
})->with([
    ['Douwyn\\StarterKit\\Modules\\Telegram\\Filament\\Resources\\Contacts\\ContactResource', 'telegram.contacts.view'],
    ['Douwyn\\StarterKit\\Modules\\WhatsApp\\Filament\\Resources\\Contacts\\ContactResource', 'whatsapp.contacts.view'],
    ['Douwyn\\StarterKit\\Modules\\Discord\\Filament\\Resources\\Contacts\\ContactResource', 'discord.contacts.view'],
    ['Douwyn\\StarterKit\\Modules\\ZaloOa\\Filament\\Resources\\ContactResource', 'zalo-oa.contacts.view'],
    ['Douwyn\\StarterKit\\Modules\\Licensing\\Filament\\Resources\\Products\\ProductResource', 'licensing.products.view'],
]);

it('honors record-level ownership even with table export and module read permissions', function (): void {
    $resource = 'Douwyn\\StarterKit\\Modules\\Domains\\Filament\\Resources\\Domains\\DomainResource';
    if (! class_exists($resource) || Gate::getPolicyFor($resource::getModel()) === null) {
        $this->markTestSkipped('The domains module is not installed.');
    }
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Permission::findOrCreate('domains.domains.view', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo(['panel.access', 'tables.export', 'domains.domains.view']);
    $this->actingAs($user);
    $model = $resource::getModel();
    $page = new ($resource::getPages()['index']->getPage());
    $table = $resource::table(Table::make($page)->query($model::query()));
    $owned = new $model(['owner_type' => 'user', 'owner_user_uuid' => $user->getKey()]);
    $foreign = new $model(['owner_type' => 'user', 'owner_user_uuid' => User::factory()->create()->getKey()]);
    expect(ExportSelectedRecords::canExport($table))->toBeTrue()
        ->and(ExportSelectedRecords::canRead($table, $owned))->toBeTrue()
        ->and(ExportSelectedRecords::canRead($table, $foreign))->toBeFalse();
});

it('downloads a real module list through Livewire without mutating its records', function (): void {
    $resource = 'Douwyn\\StarterKit\\Modules\\Telegram\\Filament\\Resources\\Contacts\\ContactResource';
    if (! class_exists($resource) || Gate::getPolicyFor($resource::getModel()) === null) {
        $this->markTestSkipped('The telegram module is not installed.');
    }
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Permission::findOrCreate('telegram.contacts.view', 'web');
    $user = User::factory()->create()->assignRole('admin');
    $user->givePermissionTo('telegram.contacts.view');
    $bot = Bot::query()->create(['name' => 'Bulk export test', 'token' => 'test-only-private-token']);
    $contact = Contact::query()->create([
        'bot_uuid' => $bot->getKey(), 'telegram_user_id' => 123456, 'first_name' => 'Selected contact',
    ]);
    Livewire\Livewire::actingAs($user)->test($resource::getPages()['index']->getPage())
        ->assertTableBulkActionVisible('exportSelected')->assertSeeHtml('fi-ta-record-checkbox')
        ->callTableBulkAction('exportSelected', [$contact])
        ->assertFileDownloaded();
    expect($contact->fresh()->first_name)->toBe('Selected contact');
});
