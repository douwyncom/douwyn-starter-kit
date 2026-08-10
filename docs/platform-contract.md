# Douwyn Starter Kit Platform Contract

## Identity

Starter-kit is the required application host, not a generic Laravel package.
The following identifiers are public and must remain stable throughout
Platform 2:

| Purpose | Identifier |
| --- | --- |
| Root Composer project | `douwyncom/douwyn-starter-kit` |
| Virtual Composer capability | `douwyncom/starter-kit-platform` |
| Current platform contract | `2.2.0` |
| Public PHP namespace | `Douwyn\StarterKit\` |
| Official module prefix | `douwyncom/starter-kit-` |

Do not rename the root Composer package in customer projects. A project may
change `APP_NAME`, its Git repository, domain, and product branding, but the
Composer root name identifies the Douwyn platform host.

## What the lock guarantees

The lock has three layers:

1. The starter-kit root provides `douwyncom/starter-kit-platform: 2.2.0`.
2. Every private module requires a compatible capability, for example `^2.2`.
   Composer therefore refuses to install it into a plain Laravel project.
3. `Douwyn\StarterKit\Platform` verifies the root package name during boot and
   `ModuleRegistry` verifies each module's runtime constraint before the
   application serves work.

This is a compatibility and distribution boundary, not DRM. Anyone who can
edit all source code can imitate a Composer capability. Actual commercial
access must still be enforced by private Git/Composer repository permissions,
read-only credentials, and the product licence.

## Platform 2 invariants

Private modules may rely on these Platform 2 contracts:

| Contract | Platform 2 value |
| --- | --- |
| PHP | `^8.5` |
| Laravel | `^13.0` |
| Filament | `^5.0` |
| Filament panel ID | `admin` |
| Authentication guard | `web` |
| User primary key | `uuid` |
| Versioned API prefix | `/api/v1` |
| Authenticated API middleware group | `starter-kit.api-authenticated` |
| Public localized API middleware group | `starter-kit.api-localized` |
| Public media disk configuration | `filesystems.media` |
| Private module disk configuration | `filesystems.private` |

Changing an invariant in a way that breaks existing modules requires Platform
3. Additive contracts may be released without changing the platform major.
Platform 2 specifically raises the minimum runtime from PHP 8.4 to PHP 8.5;
Platform 1 modules must publish matching Composer and runtime constraints before
they can be installed into this host.

## Platform 2 extension points

Platform 2 exposes public, container-resolved contracts so private modules do
not need to import application classes under `App\`:

| Extension point | Public API |
| --- | --- |
| Authenticated API stack | `Platform::API_AUTHENTICATED_MIDDLEWARE` |
| Public localized API stack | `Platform::API_LOCALIZED_MIDDLEWARE` |
| Filament panel authorization | `Contracts\PanelAccessResolver` |
| Public module media disk | `Platform::mediaDisk()` |
| Validated private module file disk | `Contracts\PrivateStorageResolver` |
| User model, table, and key | `Contracts\UserModelResolver` |
| Web/API locale selection | `Contracts\LocaleResolver` |
| Password/current-2FA step-up | `Contracts\SensitiveActionAuthorizer` and `Security\SensitiveAction*` DTOs |
| API error catalogue | `Api\ApiErrorCodeRegistry` |
| Issued token abilities | `Auth\TokenAbilityRegistry` and `Auth\TokenAbilityProfile` |

The authenticated API group executes locale resolution, `auth:sanctum`, the
active-account check, and login-session tracking in that order. Package route
files loaded directly by a module must also apply Laravel's `api` group:

```php
Route::middleware(['api', Platform::API_AUTHENTICATED_MIDDLEWARE])
    ->prefix('api/v1/example')
    ->group(__DIR__.'/example.php');
```

Public module endpoints that require locale negotiation but not authentication
use the locale-only group:

```php
Route::middleware(['api', Platform::API_LOCALIZED_MIDDLEWARE])
    ->prefix('api/v1/example')
    ->group(__DIR__.'/public-example.php');
```

Filament access is permission-based. An active user with the host-owned
`panel.access` permission may enter the `admin` panel regardless of whether
the permission came from a built-in role or a module-specific role. Modules
must grant that permission through their idempotent installer and must still
gate every resource, page, widget, and action with their own permissions and
policies.

Module media must use `Platform::mediaDisk()` rather than the private
`filesystems.default` disk directly. By default, a host using Laravel's
`local` disk stores public media on the `public` disk; a host using `s3`
inherits `s3`. `MEDIA_DISK` can override either choice:

```php
$disk = Storage::disk(Platform::mediaDisk());
$path = $disk->putFile('example', $upload);
```

Use `MEDIA_DISK=public` with `php artisan storage:link` for local deployments,
or `MEDIA_DISK=s3` with the `AWS_*` settings for S3-compatible storage. Store
the disk name and relative object path in module records, not an absolute or
temporary URL, so storage migrations remain possible.

Non-public module files resolve `Contracts\PrivateStorageResolver` from the
container. The default remains the host's private `local` disk and may inherit
a remote default such as `s3`; operators may override it with `PRIVATE_DISK`.
The resolver fails closed for missing disks, the explicit `public` disk,
`visibility=public`, and local roots below a public directory:

```php
use Douwyn\StarterKit\Contracts\PrivateStorageResolver;
use Illuminate\Support\Facades\Storage;

$disk = app(PrivateStorageResolver::class)->resolve(
    config('starter-kit-example.document_disk'),
);

Storage::disk($disk)->putFile('example/private', $upload, ['visibility' => 'private']);
```

An S3 disk may be shared with public media because S3 visibility is applied per
object; confidential writes must explicitly remain private and production
buckets should enable Block Public Access. Store the resolved disk and relative
path. A private visibility flag is not application-level content encryption.

Sensitive interactive actions such as revealing a credential resolve
`Contracts\SensitiveActionAuthorizer`. This keeps private packages away from
the host's `App\` authentication classes while enforcing the current password,
the configured authenticator/email factor when 2FA is enabled, one-time
recovery-code consumption, per-user rate limits, and security telemetry.
Email-factor screens call `sendEmailChallenge()` before `authorize()`:

```php
use Douwyn\StarterKit\Contracts\SensitiveActionAuthorizer;
use Douwyn\StarterKit\Security\SensitiveActionContext;
use Douwyn\StarterKit\Security\SensitiveActionCredentials;

$stepUp = app(SensitiveActionAuthorizer::class);
$context = new SensitiveActionContext('example.secret.reveal', $secretUuid);

$authorization = $stepUp->authorize(
    auth()->user(),
    $context,
    new SensitiveActionCredentials(
        currentPassword: $currentPassword,
        oneTimePassword: $otp,
        recoveryCode: $recoveryCode,
    ),
    request(),
);

// Call this on the same singleton, in the same request, immediately before
// executing the protected operation.
$stepUp->consume(
    auth()->user(),
    $context,
    $authorization,
    request(),
);
```

The action is a stable non-sensitive lowercase identifier; the optional subject
is HMAC-fingerprinted in telemetry. Only the exact object issued by the bound
singleton can be consumed. It is single-use, expires within 60 seconds, and is
bound to the actor, context, request/session, and current authentication state.
Constructing, cloning, serializing, or replaying the DTO does not authorize an
operation. Do not place passwords, OTPs, recovery codes, or the DTO in logs,
queues, caches, or session data.

Resolve the configured user model rather than importing `App\Models\User`:

```php
$users = app(UserModelResolver::class);

$modelClass = $users->modelClass();
$table = $users->table();
$primaryKey = $users->keyName();
```

The resolver validates that the `web` guard uses an Eloquent model which
implements Laravel's authenticatable contract. The current host resolves to
the `users` table and the `uuid` primary key.

Both Filament/web and API locale middleware use the public locale resolver.
They prefer the authenticated profile locale, then the appropriate request
preference (web session or API `Accept-Language`), then the General Settings
locale, and finally application configuration. Only configured `en` and `vi`
values are accepted.

Registries are singletons. A module may extend them during its provider's
`boot()` method; registrations then affect subsequently issued credentials,
the metadata endpoint, and generated Scramble documentation:

```php
app(ApiErrorCodeRegistry::class)->register(
    'example_conflict',
    409,
    'The example conflicts with its current state.',
);

app(TokenAbilityRegistry::class)->extend(
    TokenAbilityProfile::MOBILE,
    ['example:read'],
);
```

Duplicate identical error definitions are idempotent; conflicting definitions
fail fast. Token abilities retain insertion order and are de-duplicated. The
root defaults remain `user:read` and `user:update` for legacy tokens, with
`devices:read` and `devices:revoke` added for mobile token families.

## Independent versions

Do not use one version number for every artifact:

- Platform contract: currently `2.2.0`.
- Starter-kit Git release: the application release, for example `v1.3.0`.
- HTTP API contract: configured separately through `API_VERSION`.
- Private module: its own Git tags, for example Blog `v0.4.0`.
- Nuxt client: its own npm package version.

A module using only the original Platform 2 contracts may correctly require
`douwyncom/starter-kit-platform:^2.0`. A Blog module using the localized public
API or media-disk contracts requires `^2.1`. Module and platform release
numbers do not need to match. A module using private storage or
sensitive-action authorization contracts requires `^2.2`.

## Compatibility policy

- Module `requiresPlatform: ^2.0` and Composer `^2.0` must match.
- A module may narrow its requirement when it needs a newly added platform
  contract, for example `^2.2` after the capability is raised to `2.2.0`.
- Never widen a module constraint only to make Composer install; add a real
  compatibility implementation and tests first.
- Keep `Platform::VERSION` and the root Composer `provide` value synchronized.
  The platform contract test and local pre-release checks enforce this.
- Never reuse a released Git tag. Publish a new SemVer tag.

Inspect the active contract and registered modules with:

```bash
php artisan starter-kit:platform
php artisan starter-kit:platform --json
```
