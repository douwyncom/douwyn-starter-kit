# Douwyn Starter Kit

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

A Laravel + Filament v5 backend starter kit for Nuxt applications. Laravel owns
the API and backend services; Filament is the private administration and
monitoring surface.

Built with **PHP 8.5**, **Laravel 13**, **Filament v5**, **Livewire v4**, and **Tailwind CSS v4**.

---

## 🚀 Overview

Douwyn Starter Kit provides a robust foundation for developers who want to bypass repetitive setup and focus on building unique features. It features a clean, extensible architecture inspired by Apple's minimal design aesthetic, combined with powerful enterprise-grade functionality.

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
- **Safety Guards:** Prevent self-deactivation and protect the final Super Admin account.
- **Session Registry:** Track devices, IP addresses, activity, and revoke sessions independently of the configured Laravel session driver.
- **Global Session Administration:** Search and filter browser/Nuxt sessions for every user, revoke one or many sessions, and protect the administrator's current Filament session.
- **Security Dashboard:** Monitor active accounts, 2FA adoption, recent login sessions, failed authentication, refresh-token reuse, and revocations.
- **Security Telemetry:** Structured login, 2FA, password/security-change, and session/device-revocation events with sensitive identifiers HMAC-hashed before storage.
- **Automated Cleanup:** Scheduled pruning of expired login sessions and one-time codes.
- See the [security telemetry and dashboard guide](docs/security-telemetry.md).

### 🔌 Sanctum API Authentication
- **Dual-mode User API:** Stateful Sanctum sessions for first-party Nuxt apps and Bearer credentials for native mobile apps.
- **Mobile Token Rotation:** 15-minute access tokens, rotating refresh tokens, absolute lifetime, device binding, and refresh-token reuse detection.
- **Retry-safe Mobile Refresh:** UUID idempotency keys, database-atomic encrypted replay envelopes, preserved token scopes, and per-minute secret cleanup.
- **Account Security API:** Pending TOTP/email setup, current-factor step-up, disable flow, and one-time recovery-code regeneration.
- **Admin Separation:** API users receive no admin role; Filament is restricted to Admin and Super Admin roles.
- **Device Management:** Users and administrators can inspect/revoke mobile device families without exposing token or device hashes.
- **Nuxt SSR Safety:** The Nuxt module forwards only the configured Laravel session/XSRF cookies and never persists credentials in browser storage.
- See [API authentication documentation](docs/api-auth.md).
- See the [Nuxt 4 stateful integration and generated client](docs/nuxt-integration.md).
- See the [API lifecycle, correlation, and error catalogue](docs/api-lifecycle.md).

### 📖 Protected OpenAPI Documentation
- Scramble generates an interactive OpenAPI 3.1 reference at `/admin/api-docs`.
- Both the UI and `/admin/api-docs/openapi.json` require an active `admin` or `super_admin` account with `panel.access`.
- `packages/nuxt-api` can regenerate TypeScript contract types from the protected backend specification during development/CI.

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
- **Database Agnostic:** Optimized for both PostgreSQL (default) and MySQL.
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
- **Database:** PostgreSQL / MySQL

---

## ⚡ Installation

### Requirements

- PHP 8.5 with the extensions required by Laravel, plus GD and native
  Mbstring.
- Composer 2 and Bun.
- PostgreSQL or MySQL for the application database.
- Redis with the `phpredis` extension when running the distributed mobile-token
  concurrency suite or Redis-backed production features.

Laravel Herd users can pin this checkout to the required runtime before
installing dependencies:

```bash
herd isolate 8.5
herd php -v
herd composer install
```

### 1. Clone the repository
```bash
git clone https://github.com/douwyn/douwyn-starter-kit.git
cd douwyn-starter-kit
```

### 2. Install dependencies
```bash
composer install
bun install
```

No private registry credentials are required to install the public core. Add a
purchased module only in the consuming application's private Composer
configuration, following the instructions supplied with that module.

### 3. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Database & Starter Kit Data
Configure your database in `.env`, then run:
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
```bash
php artisan serve
bun run dev
```

In production, run Laravel's scheduler so expired sessions and verification codes are pruned:
```bash
php artisan schedule:work
```

Run the queue worker for application jobs and warm the API documentation cache
as part of each release:

```bash
php artisan queue:work --tries=3
php artisan scramble:cache
```

For PHP 8.5 production deployments, verify the runtime used by PHP-FPM and
queue workers as well as the CLI runtime:

```bash
composer check:runtime
php artisan about --only=environment
```

PHP 8.5 always loads OPcache. Remove legacy `zend_extension=opcache.so` (or
`php_opcache.dll`) directives and keep `opcache.enable=1` for the production
web SAPI. After each release, rebuild Laravel's caches and restart long-running
workers. Measure request latency, throughput, CPU, and memory against the PHP
8.4 baseline before claiming an application-level performance improvement.

---

## 🧪 Testing

Run the comprehensive test suite using Pest:

```bash
composer check:runtime
composer check:public-boundary
php artisan test
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

Mobile refresh rotation also has a separate two-process integration suite. It
requires PostgreSQL, Redis with the `phpredis` extension, and a dedicated
`douwyn_starter_kit_test` database. Never point this suite at development or
production data.

```bash
DB_DATABASE=douwyn_starter_kit_test php artisan migrate --force
vendor/bin/pest --configuration phpunit.distributed.xml
```

The suite verifies that concurrent retries with the same idempotency UUID
return the same token pair, while concurrent reuse with different UUIDs revokes
the complete mobile device family. GitHub Actions provisions isolated
PostgreSQL and Redis services and runs both the standard and distributed suites.

---

## 🗺️ Roadmap

- [x] **Account Lifecycle API:** Opaque email verification, enumeration-safe forgot/reset password, and verified email-change workflows for Nuxt and mobile.
- [x] **Core API Contract:** Generated OpenAPI/TypeScript types, pagination schemas, refresh idempotency, and documented auth error responses.
- [x] **API Lifecycle:** Machine-readable error catalogue plus correlation, versioning, sunset, and deprecation headers.
- [x] **Unified Security Telemetry:** Structured login, 2FA, refresh-reuse, password/security-change, and revocation audit events with permission-gated dashboard widgets.
- [x] **Distributed Auth CI:** PostgreSQL + Redis tests with truly concurrent refresh requests covering idempotent replay and refresh-token reuse revocation.
- [x] **Private Module Platform:** Root identity lock, Composer capability, runtime compatibility registry, Filament plugin bridge, and reusable module scaffold.
- [ ] **Security Alerts:** Queue-backed, deduplicated email/push/webhook alerts for a new device, refresh-token reuse, and repeated 2FA failures.
- [ ] **Native Client Reference Kits:** Swift and Kotlin examples for atomic secure-storage rotation, app/universal links, and offline-safe retry behavior.
- [ ] **Passkeys / WebAuthn:** Phishing-resistant sign-in and step-up authentication while retaining recovery controls.
- [ ] **Contract Compatibility Gate:** Detect breaking OpenAPI changes in CI and require an explicit version/migration note.
- [ ] **Media Manager:** Advanced local and S3 compatible file management.
- [ ] **Blog Module:** SEO-optimized content management system.
- [ ] **E-commerce Lite:** Product and inventory management.
- [ ] **Billing & Subscriptions:** Integrated financial tracking and SaaS billing.
- [ ] **Multi-tenancy:** Support for isolated team/organization environments.

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
