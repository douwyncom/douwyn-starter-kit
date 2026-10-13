<?php

use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use Composer\InstalledVersions;
use Douwyn\StarterKit\Modules\Telegram\Contracts\TelegramClient;
use Douwyn\StarterKit\Modules\Telegram\Filament\Resources\Menus\Pages\EditMenu;
use Douwyn\StarterKit\Modules\Telegram\Filament\Resources\Menus\RelationManagers\MenuItemsRelationManager;
use Douwyn\StarterKit\Modules\Telegram\Models\Bot;
use Douwyn\StarterKit\Modules\Telegram\Models\Menu;
use Douwyn\StarterKit\Modules\Telegram\Models\MenuItem;
use Douwyn\StarterKit\Modules\Telegram\Services\BotManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function requireTelegramAuditModule(): void
{
    if (! InstalledVersions::isInstalled('douwyncom/starter-kit-telegram')) {
        test()->markTestSkipped('The optional Telegram package is not installed in this host.');
    }
}

it('audits bot create edit credential rotation and delete with UUID subject and actor', function () {
    requireTelegramAuditModule();
    $user = User::factory()->create();
    $this->actingAs($user);
    $bot = Bot::query()->create([
        'name' => 'Audit bot', 'token' => 'private-token-one', 'is_active' => false,
        'settings' => ['nested' => ['api_key' => 'nested-secret', 'enabled' => true]],
    ]);
    $log = Activity::query()->where('subject_id', $bot->getKey())->sole();
    expect($log->event)->toBe('created')
        ->and($log->log_name)->toBe('module.telegram')
        ->and($log->causer_id)->toBe($user->getKey())
        ->and($log->properties['attributes']['token'])->toBe('[REDACTED]')
        ->and($log->properties['attributes']['settings']['nested']['api_key'])->toBe('[REDACTED]');

    $bot->update(['name' => 'Edited bot']);
    $log = Activity::query()->where('subject_id', $bot->getKey())->latest('id')->first();
    expect($log->properties['old']['name'])->toBe('Audit bot')
        ->and($log->properties['attributes']['name'])->toBe('Edited bot');

    $bot->save();
    expect(Activity::query()->where('subject_id', $bot->getKey())->count())->toBe(2);
    $bot->update(['token' => 'private-token-two']);
    $log = Activity::query()->where('subject_id', $bot->getKey())->latest('id')->first();
    expect($log->properties['attributes']['token'])->toBe('[REDACTED]')
        ->and($log->properties['old']['token'])->toBe('[REDACTED]');
    $uuid = $bot->getKey();
    $bot->delete();
    $logs = Activity::query()->where('subject_id', $uuid)->get();
    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'updated', 'deleted'])
        ->and($logs->toJson())->not->toContain('private-token-one', 'private-token-two', 'nested-secret');
});

it('does not retain an activity when a model transaction rolls back', function () {
    requireTelegramAuditModule();
    $uuid = null;
    try {
        (new Bot)->getConnection()->transaction(function () use (&$uuid) {
            $bot = Bot::query()->create(['name' => 'Rolled back', 'token' => 'secret']);
            $uuid = $bot->getKey();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback');
    }
    expect(Activity::query()->where('subject_id', $uuid)->exists())->toBeFalse();
});

it('logs Telegram menu creation and edits', function () {
    requireTelegramAuditModule();
    $bot = Bot::query()->create(['name' => 'Menu bot', 'token' => 'secret']);
    $menu = Menu::query()->create(['bot_uuid' => $bot->getKey(), 'name' => 'Menu', 'slug' => 'audit-menu']);
    $menu->update(['name' => 'New menu']);
    expect(Activity::query()->where('subject_id', $menu->getKey())->pluck('event')->all())
        ->toBe(['created', 'updated']);
});

it('redacts configuration values in stored core setting logs', function () {
    $setting = Setting::query()->create([
        'group' => 'integrations', 'key' => 'telegram_token', 'type' => 'string',
        'value' => 'sensitive-setting-value', 'is_encrypted' => true, 'autoload' => false,
    ]);
    $log = Activity::query()->where('subject_id', $setting->getKey())->sole();
    expect($log->properties['attributes']['value'])->toBe('[REDACTED]')
        ->and($log->toJson())->not->toContain('sensitive-setting-value');
});

it('records successful remote Telegram command changes and does not log failures', function () {
    requireTelegramAuditModule();
    $bot = Bot::query()->create([
        'name' => 'Commands bot', 'token' => 'private-token',
        'telegram_bot_id' => 998877, 'is_active' => true,
    ]);
    $client = Mockery::mock(TelegramClient::class);
    $commands = [['command' => 'start', 'description' => 'Start']];
    $client->shouldReceive('call')->once()->with('private-token', 'setMyCommands', [
        'commands' => $commands, 'language_code' => 'vi',
    ], true)->andReturn(true);
    $client->shouldReceive('call')->once()->andThrow(new RuntimeException('remote failure'));
    $manager = new BotManager($client);
    expect($manager->setCommands($bot, $commands, 'vi'))->toBeTrue();
    $log = Activity::query()->where('subject_id', $bot->getKey())->where('event', 'commands_updated')->sole();
    expect($log->properties['commands'])->toBe($commands);
    expect(fn () => $manager->setCommands($bot, $commands, 'vi'))->toThrow(RuntimeException::class);
    expect(Activity::query()->where('event', 'commands_updated')->count())->toBe(1);
});

it('audits menu item reorder single delete and bulk delete through the admin UI', function () {
    requireTelegramAuditModule();
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $actor = User::factory()->create()->assignRole('super_admin');
    $actor->givePermissionTo(Permission::findOrCreate('telegram.menus.manage', 'web'));
    $this->actingAs($actor);
    $bot = Bot::query()->create(['name' => 'Items bot', 'token' => 'secret']);
    $menu = Menu::query()->create(['bot_uuid' => $bot->getKey(), 'name' => 'Items', 'slug' => 'items']);
    $items = collect([1, 2, 3])->map(fn ($position) => MenuItem::query()->create([
        'menu_uuid' => $menu->getKey(), 'key' => 'item-'.$position, 'sort_order' => $position,
        'type' => 'text', 'action_type' => 'reply', 'label' => ['en' => 'Item '.$position],
    ]));
    $component = Livewire::actingAs($actor)->test(
        MenuItemsRelationManager::class,
        ['ownerRecord' => $menu, 'pageClass' => EditMenu::class],
    );
    expect(auth()->user()->can('telegram.menus.manage'))->toBeTrue();
    expect($component->instance()->getTable()->isReorderable())->toBeTrue();
    $component->call('loadTable');
    $component->call('reorderTable', $items->reverse()->pluck('uuid')->values()->all())->assertHasNoErrors();
    expect($items[0]->fresh()->sort_order)->toBe(3);
    expect(Activity::query()->where('subject_id', $items[0]->uuid)->where('event', 'updated')->exists())->toBeTrue();
    $component->callTableAction('delete', $items[0])->assertHasNoErrors();
    expect(Activity::query()->where('subject_id', $items[0]->uuid)->where('event', 'deleted')->exists())->toBeTrue();
    $component->callTableBulkAction('delete', $items->slice(1)->all())->assertHasNoErrors();
    foreach ($items->slice(1) as $item) {
        expect(Activity::query()->where('subject_id', $item->uuid)->where('event', 'deleted')->exists())->toBeTrue();
    }
});

it('audits both menus when publishing a new default Telegram menu', function () {
    requireTelegramAuditModule();
    $this->artisan('app:starter-kit-install')->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $actor = User::factory()->create()->assignRole('super_admin');
    foreach (['telegram.menus.manage', 'telegram.menus.view'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    $bot = Bot::query()->create(['name' => 'Publish bot', 'token' => 'secret']);
    $previous = Menu::query()->create([
        'bot_uuid' => $bot->uuid, 'name' => 'Previous', 'slug' => 'previous',
        'status' => 'published', 'is_default' => true,
    ]);
    $draft = Menu::query()->create(['bot_uuid' => $bot->uuid, 'name' => 'Draft', 'slug' => 'draft']);
    MenuItem::query()->create([
        'menu_uuid' => $draft->uuid, 'key' => 'root', 'type' => 'text', 'action_type' => 'reply',
        'label' => ['en' => 'Root'], 'is_active' => true,
    ]);
    Livewire::actingAs($actor)->test(
        EditMenu::class,
        ['record' => $draft->uuid],
    )->callAction('publish', data: ['is_default' => true])->assertHasNoErrors();
    expect($previous->fresh()->is_default)->toBeFalse()->and($draft->fresh()->is_default)->toBeTrue();
    foreach ([$previous, $draft] as $menu) {
        expect(Activity::query()->where('subject_id', $menu->uuid)->where('event', 'updated')->exists())->toBeTrue();
    }
});
