# Changelog

All notable public releases of Douwyn Starter Kit are documented here.
Application, platform, HTTP API, and Nuxt client versions are independent.

## [Unreleased]

## [1.0.1] - 2026-10-29

### Changed

- Applied PHP patch updates while retaining the existing major/minor lines,
  including Livewire 4.4.8, Sanctum 4.3.3, Scramble 0.13.52, CommonMark 2.10.4,
  Symfony 8.1 patches, and Pest 4.7.8. Laravel and Filament remain on their
  existing framework lines.
- Updated the reference Nuxt stack to 4.6.3 and Bun tooling/CI to 1.4.4, with
  compatible build-dependency updates. Retained Vite 7, TypeScript 5, Laravel
  Vite Plugin 2, and Axios 1.20 instead of unrelated major/minor upgrades.
- Removed the unused Laravel Lang development package and its abandoned
  dependencies with maintainer approval. Committed EN/VI translations and
  runtime localization are retained.

### Fixed

- Kept the 2FA challenge method schema independent of database introspection
  after the Scramble update; added regression checks for both schema states.
- Regenerated OpenAPI/TypeScript artifacts, correcting the token-list `current`
  field to boolean and recognizing the existing `session` credential literal.
  HTTP response behavior is unchanged.

### Security

- Patched the Livewire authorization bypass, Symfony Mime header injection,
  Node-forge RSA verification, and Serialize JavaScript/Seroval advisories.
- Retired the old `node-forge` exception after updating to 1.4.2 and removed the
  `braces` exception after upstream withdrew its advisory.
- Added the maintainer-approved, exact low-severity `deepmerge@4.3.1`
  development-tool exception, expiring on 2026-11-16. Every other finding
  remains blocking; see the [dependency audit policy](docs/security-audit.md).

### Upgrade notes

- Install the committed Composer/Bun lockfiles, rebuild frontend/client assets
  and application caches, then restart queue workers/reload Octane if used.
  Preserve `APP_KEY` and back up application data before deployment.
- Laravel Lang's development commands such as `lang:add` and `lang:update` are
  no longer installed. Maintain the committed translation files directly.
- The generated token-list `current` type is now boolean, matching the existing
  HTTP payload. Adjust consumers that relied on the previously incorrect string
  annotation. The independent Nuxt client package is 0.1.1 for this type fix;
  the GitHub release does not publish it to npm. Platform 2.2.0 and the HTTP API
  version are unchanged.

## [1.0.0] - 2026-10-17

This is the consolidated public baseline of the current codebase, not a rollback
to an earlier development snapshot. Pre-launch release tags were retired at
the maintainer's request; the source commit history remains intact.

### Included

- PHP 8.5, Laravel 13, Filament 5.8.4, Livewire 4, and Tailwind CSS 4 backend
  and administration stack, with English/Vietnamese localization.
- Sanctum session authentication for first-party Nuxt applications and mobile
  access tokens with rotating refresh tokens, device binding, idempotent retry,
  and refresh-token reuse revocation.
- Email verification, password recovery, email changes, TOTP/email two-factor
  authentication, recovery controls, and branded HTML/plain-text OTP emails.
- Permission-controlled user, role, permission, setting, and login-session
  administration, security dashboard widgets, and protected Scramble API docs.
- Permission-gated table selection, bounded CSV exports, and bulk actions with
  current-session, self-deactivation, and last-super-admin protections.
- Authenticated encryption of personal profile data, verified legacy-data
  migration, safe key rotation, and redaction of sensitive audit properties.
- Read-only system activity logs with explicit record IDs and UUID references.
- Nuxt 4.6.2 reference client stack, Nuxt Kit 4.6.2, Bun 1.4.3 tooling, generated
  OpenAPI/TypeScript contracts, and SSR-safe cookie/header forwarding.
- Platform 2.2.0 extension contracts, private/public storage conventions, scoped
  services, and optional Octane/Swoole deployment configuration.
- Apache-2.0 licensing, contribution/security/support policies, bilingual
  integration/operations guides, and GitHub Quality/tag-release automation.
- A standalone public dependency graph and source archive excluding commercial
  modules, private integration fixtures, and local environment data.

### Security and verification

- Includes the CommonMark 2.10.3, Symfony HttpFoundation/Yaml 8.1.9, and compatible
  JavaScript dependency security updates from the development baseline.
- The JavaScript audit policy still has two exact, maintainer-approved Nuxt
  development-tool exceptions expiring on 2026-11-16. They are not a claim of
  zero vulnerabilities and do not apply to downstream production dependency
  paths. See the [dependency audit policy](docs/security-audit.md).
- Public checks cover 169 PHP tests, 13 Nuxt client tests, 5 audit-policy tests,
  PHP formatting, frontend/client builds, and generated contract reproducibility.
  PostgreSQL/Redis concurrency and real Swoole worker behavior require separate
  deployment-level validation.

### Installation and deployment notes

- Follow the [installation guide](https://github.com/douwyncom/douwyn-starter-kit/blob/v1.0.0/README.md) and committed lockfiles.
  Run migrations and `php artisan app:starter-kit-install`, then build assets.
- Existing development installations must back up the database and preserve
  `APP_KEY`. Review [profile encryption](docs/profile-data-encryption.md) before
  migrating plaintext data or rotating keys.
- Nuxt runs on Bun 1.4.3; when using Node.js instead, Nuxt 4.6.2 requires
  `^22.22.3 || ^24.15.0 || >=26.0.0`. See the [Nuxt guide](docs/nuxt-integration.md).
- Restart queue workers/reload Octane after deployments when those services are
  used. Conventional Laravel serving does not require Swoole.
- `tables.export` is granted to the default admin and super-admin roles; grant it
  explicitly to any other role authorized to export data.
- Application version 1.0.0 does not reset Platform 2.2.0 or the independent Nuxt
  client package version. Commercial modules remain separately distributed.

[Unreleased]: https://github.com/douwyncom/douwyn-starter-kit/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/douwyncom/douwyn-starter-kit/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/douwyncom/douwyn-starter-kit/releases/tag/v1.0.0
