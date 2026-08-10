# Private module development

Private modules extend Douwyn Starter Kit and intentionally do not support a
plain Laravel host. Laravel package auto-discovery loads the module provider;
the platform base provider then registers compatibility metadata and attaches
an optional Filament plugin to the `admin` panel without modifying the root
`AdminPanelProvider`.

## Licensing and source boundary

Each commercial module lives in its own private repository and carries its own
commercial licence or EULA. The public starter-kit repository, its Composer
lock file, generated OpenAPI/Nuxt artifacts, and public tests must remain usable
without the module or private registry credentials.

The root `modules/` directory is an ignored local integration workspace only.
Never commit its contents or place module-specific integration tests outside it
in the public repository. Prefer a separate private consumer fixture for
cross-repository tests and generated module API contracts.

The scaffold under `stubs/module` is distributed as part of the Apache-2.0
public core. A module created from it may be offered under commercial terms,
but any copied Apache-licensed or third-party code continues to require its
original licence and notices. Keep the module's proprietary authorship and
third-party material clearly identifiable.

## Naming

Use one slug consistently:

| Item | Blog example |
| --- | --- |
| Git repository | `starter-kit-blog` |
| Composer package | `douwyncom/starter-kit-blog` |
| PHP namespace | `Douwyn\StarterKit\Modules\Blog` |
| Service provider | `BlogServiceProvider` |
| Filament plugin ID | `douwyn-starter-kit-blog` |

Reserved platform packages use the `douwyncom` Composer vendor. Application
code remains in `App\`; reusable private module code belongs in its module
namespace.

## Create a module repository

Copy the maintained scaffold and replace `Example` / `example`:

```bash
cp -R stubs/module ../starter-kit-blog
cd ../starter-kit-blog
```

The module `composer.json` must retain the platform requirement and Laravel
provider auto-discovery:

```json
{
    "name": "douwyncom/starter-kit-blog",
    "type": "library",
    "license": "proprietary",
    "homepage": "https://douwyn.com",
    "require": {
        "php": "^8.5",
        "composer-runtime-api": "^2.2",
        "douwyncom/starter-kit-platform": "^2.2",
        "filament/filament": "^5.0",
        "illuminate/support": "^13.0"
    },
    "support": {
        "email": "contact@douwyn.com"
    },
    "autoload": {
        "psr-4": {
            "Douwyn\\StarterKit\\Modules\\Blog\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Douwyn\\StarterKit\\Modules\\Blog\\BlogServiceProvider"
            ]
        }
    }
}
```

Do not add a Composer `version` field. Composer derives the module version from
its Git tag. At runtime, `Composer\InstalledVersions` reports that tag to the
platform registry.

## Provider and Filament plugin

Extend the platform provider instead of Laravel's provider directly:

```php
final class BlogServiceProvider extends ModuleServiceProvider
{
    protected function module(): StarterKitModule
    {
        return new BlogModule;
    }

    protected function registerModule(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blog.php', 'starter-kit-blog');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'starter-kit-blog');
    }
}
```

`BlogModule` implements `StarterKitModule`. If it also implements
`ProvidesFilamentPlugin`, the base provider attaches its plugin only to the
platform's `admin` panel. The plugin's `register()` method should add its own
Filament resources, pages, clusters, and widgets.

Keep module ownership explicit:

- migrations live inside the module and only add or remove module tables;
- routes are loaded by the module provider and use a module-specific name;
- scheduled jobs and commands are registered by the module provider;
- translations, views, configuration, and assets use a module-specific key;
- permissions and default data are installed by an idempotent module command;
- uninstall must never delete customer data without an explicit destructive
  option;
- a module must not edit files under the root starter-kit during installation.

## Use Platform 2 contracts

Do not import `App\Models\User` or copy the host's authenticated middleware
stack into a module. Resolve `UserModelResolver` for the configured model,
table, and key, and protect module API routes with the public group:

```php
use Douwyn\StarterKit\Contracts\UserModelResolver;
use Douwyn\StarterKit\Platform;

$users = app(UserModelResolver::class);

Route::middleware(['api', Platform::API_AUTHENTICATED_MIDDLEWARE])
    ->prefix('api/v1/blog')
    ->group(function () use ($users): void {
        // $users->modelClass(), $users->table(), $users->keyName()
    });
```

Public translated endpoints use the locale-only Platform 2.1 group and do not
copy authentication middleware:

```php
Route::middleware(['api', Platform::API_LOCALIZED_MIDDLEWARE])
    ->prefix('api/v1/blog')
    ->group(__DIR__.'/../routes/public-api.php');
```

The platform middleware resolves `en` or `vi` using the starter-kit profile,
request/session, General Settings, and application fallbacks. A module should
only load namespaced translations; it must not install another locale
middleware.

Module-specific Filament roles may enter the admin panel by receiving the
host-owned `panel.access` permission. Do not require a built-in `admin` role
and do not treat panel entry as authorization for module resources or actions;
continue to enforce the module's permission and policy matrix.

Public media uses the host convention instead of a module-specific hard-coded
disk:

```php
$disk = Storage::disk(Platform::mediaDisk());
```

The host maps its private `local` default to the public `public` disk and
inherits remote defaults such as `s3`. Operators may override the choice with
`MEDIA_DISK`; module records should retain both disk and relative path.

Modules register domain error codes and token abilities in `boot()`:

```php
app(ApiErrorCodeRegistry::class)->register(
    'blog_post_conflict',
    409,
    'The post conflicts with its current state.',
);

app(TokenAbilityRegistry::class)->extend(
    TokenAbilityProfile::MOBILE,
    ['blog:read'],
);
```

Error-code extensions automatically appear in `/api/v1/meta/error-codes` and
the Scramble `ApiErrorCode` schema. Ability extensions affect newly issued
tokens; existing tokens retain the abilities recorded when they were issued.
If a module uses the localized-public-API or media-disk contracts, its Composer
requirement and runtime `requiresPlatform` value must both be `^2.1`.

Sensitive module files resolve `PrivateStorageResolver` rather than using the
public media contract. It validates the configured/default local or S3 disk and
rejects public disks and local public roots:

```php
use Douwyn\StarterKit\Contracts\PrivateStorageResolver;

$disk = app(PrivateStorageResolver::class)->resolve(
    config('starter-kit-blog.document_disk'),
);
```

Secret reveal, private-key download, and similar interactive operations use
`SensitiveActionAuthorizer` with `SensitiveActionContext` and
`SensitiveActionCredentials`. The host adapter verifies the current password
plus the enabled authenticator/email factor, accepts a one-time recovery code,
rate limits failures, and records sanitized security telemetry. For email 2FA,
call `sendEmailChallenge()` after the user supplies their current password,
then call `authorize()` with the received OTP. Call `consume()` on the same
singleton with the exact returned authorization immediately before the
protected operation. The authorization is one-time, request/session-bound, and
expires within 60 seconds; never construct, clone, persist, cache, or queue it
or the credentials DTO.

Modules relying on private storage or sensitive-action authorization must
require `^2.2` in both Composer and their runtime manifest.

## Develop through a starter-kit host

The module is deliberately resolved from a compatible starter-kit root. Keep
both repositories next to each other and use a symlinked Composer path
repository from the starter-kit:

```bash
cd ../douwyn-starter-kit
composer config repositories.starter-kit-blog path ../starter-kit-blog
composer require douwyncom/starter-kit-blog:@dev
php artisan starter-kit:platform
```

Composer writes both the path repository and package requirement to the root
Composer manifests. Do not commit either change to the public core. Before a
public-core release, remove the commercial requirement and repository, update
the lock file, reinstall dependencies, clear Laravel package-discovery caches,
and regenerate OpenAPI/Nuxt artifacts without the module. A dedicated private
integration fixture is safer for ongoing module work.

Run the complete host suite after each module change:

```bash
php artisan test --compact
vendor/bin/pint --test
php artisan route:cache
php artisan config:cache
php artisan filament:cache-components
php artisan optimize:clear
```

The module repository may have unit tests for isolated logic, but its release
gate must install the package into the supported starter-kit version because
that is the only supported consumer. Keep module-specific host tests, Scramble
snapshots, and generated client contracts in that private release gate.

## Release and install

Tag modules independently:

```bash
git tag -a v0.1.0 -m "Starter Kit Blog v0.1.0"
git push origin main v0.1.0
```

For a small number of modules, register the private VCS repository in the
starter-kit root and require a tagged version:

```bash
composer config repositories.starter-kit-blog vcs \
  git@github.com:<github-owner>/starter-kit-blog.git
composer require douwyncom/starter-kit-blog:^0.1
```

When the catalogue grows, expose all module metadata through one private
Composer-compatible registry such as Private Packagist or Satis. Repository
definitions are root-only Composer configuration, which fits this model: only
the mandatory starter-kit root knows where private modules are distributed.

Never commit access tokens or `auth.json`. Give customer and deployment tokens
read-only access to only the repositories covered by their licence.
