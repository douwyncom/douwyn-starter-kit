<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\TrackLoginSession;
use App\Models\User;
use Composer\InstalledVersions;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Douwyn\StarterKit\Contracts\ProvidesFilamentPlugin;
use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Contracts\UserModelResolver;
use Douwyn\StarterKit\Modules\ModuleManifest;
use Douwyn\StarterKit\Modules\ModuleRegistry;
use Douwyn\StarterKit\Modules\ModuleServiceProvider;
use Douwyn\StarterKit\Platform;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

it('keeps the composer capability synchronized with the runtime contract', function () {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['name'])->toBe(Platform::ROOT_PACKAGE)
        ->and($composer['require']['php'])->toBe('^8.5')
        ->and($composer['require']['ext-mbstring'])->toBe('*')
        ->and($composer['config']['platform']['php'])->toBe('8.5.0')
        ->and($composer['provide'][Platform::CAPABILITY])->toBe(Platform::VERSION)
        ->and(Platform::hostPackage())->toBe(Platform::ROOT_PACKAGE)
        ->and(Platform::supports('^1.1'))->toBeFalse()
        ->and(Platform::supports('^2.0'))->toBeTrue()
        ->and(Platform::supports('^3.0'))->toBeFalse();
});

it('exposes the frozen platform two invariants', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80500)
        ->and(extension_loaded('mbstring'))->toBeTrue()
        ->and(extension_loaded('gd'))->toBeTrue()
        ->and(extension_loaded('Zend OPcache'))->toBeTrue()
        ->and(InstalledVersions::getPrettyVersion('laravel/framework'))->toStartWith('v13.')
        ->and(InstalledVersions::getPrettyVersion('filament/filament'))->toStartWith('v5.')
        ->and(config('auth.defaults.guard'))->toBe(Platform::AUTH_GUARD)
        ->and((new User)->getKeyName())->toBe(Platform::USER_KEY)
        ->and(Filament::getPanel(Platform::ADMIN_PANEL_ID)->getId())->toBe(Platform::ADMIN_PANEL_ID)
        ->and(app(ModuleRegistry::class))->toBe(app(ModuleRegistry::class))
        ->and(app(ApiErrorCodeRegistry::class))->toBe(app(ApiErrorCodeRegistry::class))
        ->and(app(TokenAbilityRegistry::class))->toBe(app(TokenAbilityRegistry::class));
});

it('exposes the configured user model without importing the application model in modules', function () {
    $users = app(UserModelResolver::class);

    expect($users->modelClass())->toBe(User::class)
        ->and($users->newModel())->toBeInstanceOf(User::class)
        ->and($users->table())->toBe('users')
        ->and($users->keyName())->toBe(Platform::USER_KEY);
});

it('publishes the stable authenticated api middleware group in execution order', function () {
    $group = app('router')->getMiddlewareGroups()[Platform::API_AUTHENTICATED_MIDDLEWARE] ?? null;

    expect($group)->toBe([
        SetApiLocale::class,
        'auth:sanctum',
        EnsureUserIsActive::class,
        TrackLoginSession::class,
    ]);

    $route = Route::middleware(['api', Platform::API_AUTHENTICATED_MIDDLEWARE])
        ->get('/_platform-contract-middleware-order', static fn (): array => []);
    $resolved = app('router')->gatherRouteMiddleware($route);
    $localeIndex = array_search(SetApiLocale::class, $resolved, true);
    $authIndex = array_search(Authenticate::class.':sanctum', $resolved, true);
    $throttleIndex = array_search(ThrottleRequests::class.':api', $resolved, true);

    expect($localeIndex)->toBeInt()
        ->and($authIndex)->toBeInt()
        ->and($throttleIndex)->toBeInt()
        ->and($localeIndex)->toBeLessThan($authIndex)
        ->and($localeIndex)->toBeLessThan($throttleIndex);
});

it('auto-registers a private module and its filament plugin', function () {
    $plugin = new class implements Plugin
    {
        public function getId(): string
        {
            return 'contract-test-module';
        }

        public function register(Panel $panel): void {}

        public function boot(Panel $panel): void {}
    };
    $module = new readonly class($plugin) implements ProvidesFilamentPlugin, StarterKitModule
    {
        public function __construct(private Plugin $plugin) {}

        public function manifest(): ModuleManifest
        {
            return new ModuleManifest('douwyncom/starter-kit-contract-test', '0.1.0', '^2.0');
        }

        public function filamentPlugin(): Plugin
        {
            return $this->plugin;
        }
    };
    $provider = new class(app(), $module) extends ModuleServiceProvider
    {
        public function __construct($app, private StarterKitModule $starterKitModule)
        {
            parent::__construct($app);
        }

        protected function module(): StarterKitModule
        {
            return $this->starterKitModule;
        }
    };
    $provider->register();

    $panel = Panel::make()->id(Platform::ADMIN_PANEL_ID);
    $manifest = app(ModuleRegistry::class)->manifests()[0];

    expect($panel->hasPlugin('contract-test-module'))->toBeTrue()
        ->and($manifest->package)->toBe('douwyncom/starter-kit-contract-test')
        ->and($manifest->requiresPlatform)->toBe('^2.0');
});

it('reports the platform contract through artisan', function () {
    expect(Artisan::call('starter-kit:platform', ['--json' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('"root_package": "douwyncom/douwyn-starter-kit"')
        ->toContain('"version": "2.0.0"');
});
