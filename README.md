# Douwyn Starter Kit

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

A Laravel + Filament v5 backend starter kit for Nuxt applications. Laravel owns
the API and backend services; Filament is the private administration and
monitoring surface.

Built with **PHP 8.5**, **Laravel 13**, **Laravel Octane + Swoole**,
**Filament v5**, **Livewire v4**, and **Tailwind CSS v4**.

---

## 🚀 Overview

The starter kit supplies account authentication, administration, permissions,
and a typed Nuxt API client. Use it as the root Laravel application, then add
project features or compatible modules. The included admin interface uses a
minimal visual design and supports English and Vietnamese.

### Open-source core and commercial modules

The application core and the official `@douwyn/nuxt-api` client are open
source under the [Apache License 2.0](LICENSE). A clean clone installs, builds,
and passes its public test suite without access to a paid package.

Optional Douwyn modules are separate Composer packages sold and distributed
through private repositories or a private Composer registry. They use their
own commercial licences and are never included in this repository. The local
`modules/` directory is reserved for private integration work and is excluded
from Git, release archives, root dependencies, and generated public API
artifacts. See the [commercial module boundary](docs/commercial-modules.md).
The [Vietnamese commercial-module guide](docs/commercial-modules.vi.md) is maintained as a translation
of that canonical English document.

Apache-2.0 permits use, modification, and redistribution of the public core,
including commercial use. Access to paid modules, the Douwyn trademarks, and
commercial support are separate from that licence.

---

## ✨ Key Features

### 🔐 Advanced Authentication
- **Multi-Factor Authentication (MFA/2FA):**
  - Secure Email OTP delivery.
  - Authenticator App support (TOTP).
  - One-time recovery codes stored as irreversible hashes.
- **Security & Protection:**
  - Robust rate limiting on all auth endpoints.
  - Account status management (Active/Inactive).
  - Securely hashed and consumed one-time codes.
- **Refined UX:** Custom-built, rate-limited admin login with enforced panel authorization.

### 🛡️ Role & Permission Management
- **Spatie Integration:** Powered by the industry-standard `spatie/laravel-permission`.
- **Unified Interface:** Manage roles and create permissions directly within intuitive Filament forms.
- **Guard-Aware:** Architecture designed to handle multiple guards seamlessly.
- **Optimized UI:** High-performance permission selection interface.

### 👥 User & Session Management
- **User Administration:** Create and update users, profiles, roles, account status, passwords, and email verification state.
- **Personal Data Protection:** Profile identity, contact, address, biography, preference, and metadata fields use authenticated encryption at rest and are excluded from generic model serialization.
- **Safety Guards:** Prevent self-deactivation and protect the final Super Admin account.
- **Session Registry:** Track devices, IP addresses, activity, and revoke sessions independently of the configured Laravel session driver.
- **Global Session Administration:** Search and filter browser/Nuxt sessions for every user, revoke one or many sessions, and protect the administrator's current Filament session.
- **Security Dashboard:** Monitor active accounts, 2FA adoption, recent login sessions, failed authentication, refresh-token reuse, and revocations.
- **Security Telemetry:** Structured login, 2FA, password/security-change, and session/device-revocation events with sensitive identifiers HMAC-hashed before storage.
- **Automated Cleanup:** Scheduled pruning of expired login sessions and one-time codes.
- See the [user profile encryption and key-rotation guide](docs/profile-data-encryption.md).
- See the [security telemetry and dashboard guide](docs/security-telemetry.md).

### 🔌 Sanctum API Authentication
- **Dual-mode User API:** Stateful Sanctum sessions for first-party Nuxt apps and Bearer credentials for native mobile apps.
- **Mobile Token Rotation:** 15-minute access tokens, rotating refresh tokens, absolute lifetime, device binding, and refresh-token reuse detection.
- **Retry-safe Mobile Refresh:** UUID idempotency keys, database-atomic encrypted replay envelopes, preserved token scopes, and per-minute secret cleanup.
- **Account Security API:** Pending TOTP/email setup, current-factor step-up, disable flow, and one-time recovery-code regeneration.
- **Admin Separation:** API registration grants no panel access. Filament requires an active account with `panel.access`; the default Admin and Super Admin roles receive that permission.
- **Device Management:** Users and administrators can inspect/revoke mobile device families without exposing token or device hashes.
- **Nuxt SSR Safety:** The Nuxt module forwards only the configured Laravel session/XSRF cookies and never persists credentials in browser storage.
- See [API authentication documentation](docs/api-auth.md).
- See the [Nuxt 4 stateful integration and generated client](docs/nuxt-integration.md).
- See the [API lifecycle, correlation, and error catalogue](docs/api-lifecycle.md).

### 📖 Protected OpenAPI Documentation
- Scramble generates an interactive OpenAPI 3.1 reference at `/admin/api-docs`.
- Both the UI and `/admin/api-docs/openapi.json` require an active account authorized for the admin panel through `panel.access`.
- `packages/nuxt-api` can regenerate TypeScript contract types from the protected backend specification during development or before a release.

### 🎨 Design & UI/UX
- **Filament v5:** Leveraging the latest TALL stack admin panel features.
- **Tailwind CSS v4:** Using the cutting-edge utility-first CSS framework.
- **Apple-Inspired Design:** Clean, minimal, and high-contrast interface.
- **Dynamic Theming:** Native Dark and Light mode support with a customizable OKLCH color palette.
- **Responsive:** Fully adaptive layouts for mobile, tablet, and desktop.

### 🏗️ Architecture
- **Clean Code:** Structured Filament Resources with separation of Schemas, Tables, and Pages.
- **Commercial Module Boundary:** Paid packages require the Douwyn Starter Kit Platform 2 capability, remain in private distribution, and are rejected by unsupported hosts.
- **Plug-in Filament Modules:** Auto-discovered packages can attach their own resources, pages, widgets, migrations, routes, and commands without editing the root panel provider.
- **Database:** PostgreSQL is the default application database; MySQL is configurable. The public automated suite uses SQLite in memory.
- **Long-lived Runtime:** Octane/Swoole configuration, scoped request services,
  worker recycling, strict production preflight checks, and graceful reload
  guidance are included.
- **Developer Experience:** Production-oriented defaults and helper commands.

The starter-kit remains the mandatory root application. It is not converted
into a generic Laravel dependency. See the [platform contract](docs/platform-contract.md),
[private module guide](docs/module-development.md), and
[Git release workflow](docs/git-release.md). The ownership and distribution
boundary is summarized in [commercial modules](docs/commercial-modules.md).

---

## 🛠️ Tech Stack

- **Framework:** Laravel 13
- **Admin Panel:** Filament v5
- **Frontend:** Livewire v4, Tailwind CSS v4
- **Auth:** Spatie Permission, Custom 2FA
- **Application Server:** Laravel Octane with Swoole
- **Database:** PostgreSQL / MySQL

---

## ⚡ Installation

### Requirements

- PHP 8.5 with the extensions required by Laravel, plus GD, native Mbstring,
  and OPcache. Run `composer check-platform-reqs` and `composer check:runtime`
  after installing dependencies to verify the active CLI runtime.
- Composer 2.2 or newer and Bun 1.3 or newer. Vite also needs Node.js 20.19+
  or 22.12+ when its CLI runs under Node.
- PostgreSQL or MySQL and the corresponding PHP PDO extension for the
  application database; PDO SQLite for the public automated tests.
- Redis with the `phpredis` extension for Redis-backed features; the default
  local database-backed session, cache, and queue drivers do not require Redis.
- The stable Swoole extension for the PHP 8.5 CLI runtime when serving the
  application through Octane. It is optional for the conventional local
  `artisan serve` workflow.

Laravel Herd users can pin this checkout to the required runtime before
installing dependencies:

```bash
herd isolate 8.5
herd php -v
herd composer install
```

### 1. Clone the repository
```bash
git clone https://github.com/douwyncom/douwyn-starter-kit.git
cd douwyn-starter-kit
```

### 2. Install dependencies
```bash
composer install
bun install --frozen-lockfile
```

No private registry credentials are required to install the public core. Add a
purchased module only in the consuming application's private Composer
configuration, following the instructions supplied with that module.

### 3. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

Generate a key only for a new installation. Keep the existing `APP_KEY` when
upgrading because it protects stored personal data and authentication secrets.
Set `APP_URL` to the actual Laravel origin (for example,
`http://localhost:8000` for `artisan serve`).

### 4. Database & Starter Kit Data
Create the database and a database user first, then configure the matching
`DB_*` values in `.env`. For MySQL, also set `DB_CONNECTION=mysql` and
`DB_PORT=3306`. Run:
```bash
php artisan migrate
php artisan app:starter-kit-install
```
*This command seeds default roles (Super Admin, Admin, Staff), permissions, and system settings.*

Inspect the platform identity and installed private modules at any time:

```bash
php artisan starter-kit:platform
```

The installer also grants `login_sessions.view` and
`login_sessions.revoke` to the default Admin and Super Admin roles. Run the
command again after upgrading an existing installation so the global Login
Sessions resource becomes available.

### 5. Create Super Admin
```bash
php artisan app:starter-kit-user
```

### 6. Start Development
Run these in separate terminals:

```bash
php artisan serve
```

```bash
bun run dev
```

Open `http://localhost:8000/admin` and sign in with the Super Admin account.
The Laravel application does not include a Nuxt frontend; integrate a separate
Nuxt app using the [client guide](docs/nuxt-integration.md).

Run a queue worker in another terminal for queued account email notifications:

```bash
php artisan queue:work --tries=3 --timeout=60
```

The default `MAIL_MAILER=log` writes email to `storage/logs/laravel.log`.
Configure a real mail transport before enabling email flows for users. When
serving public media from the local `public` disk, run `php artisan storage:link`.

To exercise the long-lived runtime, install Swoole for the active PHP 8.5 CLI
and use Octane as the HTTP server after stopping `artisan serve` on port 8000:

```bash
composer check:octane
php artisan octane:start --server=swoole --host=127.0.0.1 --workers=1 --task-workers=1 --max-requests=100
```

See the complete [English Octane/Swoole operations guide](docs/octane-swoole.md)
or [Vietnamese guide](docs/octane-swoole.vi.md) before using this runtime in
production.

In production, supervise the scheduler and queue worker separately from the
HTTP server. A scheduler host can invoke Laravel once per minute through cron:

```cron
* * * * * cd /path/to/douwyn-starter-kit && php artisan schedule:run >> /dev/null 2>&1
```

Use `php artisan schedule:work` for local testing or a separately supervised
scheduler process. Set `APP_ENV=production`, `APP_DEBUG=false`, the correct
HTTPS `APP_URL`, and secure session cookies in production. Preserve and back up
the encryption keys separately from the database.

Run the queue worker for application jobs and warm the API documentation cache
as part of each release:

```bash
php artisan scramble:cache
```

The queue worker runs continuously in its own process:

```bash
php artisan queue:work --tries=3 --timeout=60 --memory=384 --max-jobs=500 --max-time=3600
```

For Octane/Swoole production deployments, verify the exact CLI runtime used by
the process monitor, then rebuild caches and gracefully reload every long-lived
process after a release:

```bash
composer check:runtime
composer check:octane
php artisan about --only=environment
php artisan octane:reload
php artisan queue:restart
```

Keep the Octane listener reset list intact, use a shared cache for sessions,
rate limits, and distributed locks, and never expose the Octane port directly
to the internet. Start with bounded request lifetimes and measure per-worker
RSS, latency, database connections, and file descriptors across several worker
generations before production rollout.

---

## 🧪 Testing

The public Pest suite uses an in-memory SQLite database and does not need
PostgreSQL, Redis, Swoole, or paid modules. Clear cached configuration before
running it, and use `composer test` so the project script performs that step:

```bash
composer check:runtime
composer check:public-boundary
composer test
vendor/bin/pint --test
bun run build
bun run nuxt-api:typecheck
bun run nuxt-api:test
bun run nuxt-api:build
```

The root Composer capability, runtime platform version, module registry, and
Filament plugin attachment are covered by the platform contract tests. The
Nuxt checks remain intentional because `@douwyn/nuxt-api` is an official
starter-kit client component.

The public suite does not include a two-process mobile-token concurrency test.
The retained `phpunit.distributed.xml` is configuration for optional integration
fixtures; a clean clone has no `tests/Integration` suite to run. Validate
concurrency and database-specific behavior in a dedicated test environment
before deploying those workloads. Never point integration fixtures at
development or production data.

For contribution requirements and the maintainer release checklist, see
[CONTRIBUTING.md](CONTRIBUTING.md) and [Git releases](docs/git-release.md).

---

## 📬 Contact and bug reports

- Email: **[contact@douwyn.com](mailto:contact@douwyn.com)**
- Website: **[https://douwyn.com](https://douwyn.com)**

When reporting a bug, include the starter-kit version, environment details,
expected behavior, actual behavior, and a minimal reproduction. Security
vulnerabilities must be reported privately.

---

## 🛡️ Security

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).
General community support and paid-module support are separated in
[SUPPORT.md](SUPPORT.md).

---

## 📜 License

The public Douwyn Starter Kit core and `packages/nuxt-api` are licensed under
the [Apache License 2.0](LICENSE). Retain the attribution in [NOTICE](NOTICE)
when required by that licence.

Commercial modules are distributed separately under their own proprietary
licence terms and are not part of this repository. The open-source licence
does not grant trademark rights; see [TRADEMARKS.md](TRADEMARKS.md).
