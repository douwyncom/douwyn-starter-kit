# Changelog

All notable changes to Douwyn Starter Kit are documented here. Releases use
Semantic Versioning for the application; the platform, HTTP API, Nuxt client,
and private modules maintain their own compatibility versions.

## [Unreleased]

## [1.2.0] - 2026-09-12

### Added

- Added the optional SystemInsightsReader contract for authorized user
  statistics, queue aggregates, and bilingual access instructions.
- Platform 2.2 private-storage and sensitive-action authorization contracts,
  with permission-based Filament panel access for least-privilege roles.
- Laravel Octane with Swoole configuration, scoped request services, runtime
  preflight checks, Supervisor template, and English/Vietnamese operations guides.
- English/Vietnamese localization coverage for UI, validation, API errors,
  account notifications, and two-factor email delivery.
- GitHub Actions quality gates and tag-based releases, plus issue and pull
  request templates for public contributions.

### Changed

- Raised the independent platform capability from `2.1.0` to `2.2.0`.
- Redesigned the Filament administration theme and clarified panel access,
  installation, Nuxt package builds, upgrade steps, and public test coverage.
- Made generated OpenAPI server URLs relative to the current host so the
  public contract is reproducible across development and CI environments.
- Canonicalized boolean setting metadata while retaining existing `boolean`
  rows, and corrected generated nullable/date-time API metadata.
- Included the changelog in source release archives.

### Fixed

- Fixed PHP cast spacing in the platform service provider.
- Made public OpenAPI response metadata reproducible with empty or migrated
  databases, preserving nullable profile fields and date-time formats. Corrected
  the generated name types for users without a profile, aligned Nuxt user
  timestamp types, and added regression coverage for schema-independent generation.
- Preserved module-specific API error codes and continued rendering the
  original error when locale resolution fails.
- Return validation errors for malformed email, password, and token JSON
  inputs instead of failing before validation.
- Preserved locale selection through Livewire requests, account/settings
  changes, pending 2FA logins, public pages, and early API exceptions.
- Honored `.env` Octane runtime requirements and exported environment
  overrides; corrected the OpenSwoole PHP 8.5 minimum version check.
- Refreshed user-model configuration, storage resolvers, sensitive-action
  authorization state, and permission state between Octane operations.
- Corrected Nginx PHP/dotfile handling and queue timeout guidance in the
  deployment examples.

### Security

- Invalidated pending Filament 2FA challenges when passwords or factor
  configuration change.
- Updated Filament to 5.7.8 and Livewire to 4.4.3 to address published security
  advisories; updated Browserslist to the patched 4.28.9 release line.
- Re-query and lock current profile rows during encryption-key rotation,
  preventing concurrent profile edits from being overwritten.
- Strengthened the public-boundary guard against private modules and
  non-core routes appearing in tracked files or generated API contracts.

### Upgrade notes

- Keep `APP_KEY` unchanged and back up the database and encryption keys.
  Review [profile encryption](docs/profile-data-encryption.md) before upgrading
  from a release older than 1.1.0.
- Install the committed lockfiles, run `php artisan migrate --force` and
  `php artisan app:starter-kit-install`, rebuild frontend assets and application
  caches, then restart queue workers and reload Octane if used.
- Private modules must declare compatibility with Platform 2.2 before use.
  Application, platform, HTTP API, and Nuxt package versions are independent.
- Users already waiting for a 2FA code during deployment must restart login.
- Conventional Laravel serving remains supported. Octane requires a compatible
  Swoole/OpenSwoole extension; follow the operations guide and validate worker
  isolation and load on the actual deployment environment before rollout.

## [1.1.0] - 2026-07-28

### Added

- Platform 2.1 locale-only middleware for public, translated module APIs.
- Platform 2.1 public media-disk convention with local-public and S3 support.
- Local release-check procedure for PHP, Nuxt, and distributed authentication
  tests.
- Authenticated at-rest encryption for personal data in `user_profiles`,
  including typed encrypted date/enum casts, verified legacy-data migration,
  tamper detection, and a current-key re-encryption command.
- Profile encryption deployment, backup, query-limitation, and `APP_KEY`
  rotation documentation.

### Changed

- Raised the commercial-module platform contract from `2.0.0` to `2.1.0`.
- Replaced the removed GitHub quality workflow with documented local release
  checks and the Composer public-boundary command.
- Disabled database search/sort operations that depended on encrypted profile
  names, and redacted profile PII keys from security activity properties.

### Security

- Protected profile fields are hidden from generic model serialization while
  authorized API resources and Filament forms continue to receive decrypted
  values.
- Added `APP_PREVIOUS_KEYS` guidance and a verified
  `app:user-profiles-reencrypt` workflow for safe application-key rotation.

## [1.0.0] - 2026-07-23

### Added

- Apache-2.0 licensing for the public core and official Nuxt API package.
- Community contribution, conduct, security, support, notice, and trademark
  policies for public-source development.
- Commercial-module boundary documentation plus Git and Composer guards that
  reject private module sources and dependencies from the public repository.
- Platform 2 authenticated API middleware and configured user-model resolver.
- Shared `en`/`vi` locale resolution for web and API requests.
- Extensible API error-code and token-ability profile registries.
- Platform 2 compatibility capability and runtime host validation.
- Stable `Douwyn\StarterKit` contracts, module registry, and diagnostic command.
- Auto-discovered module provider with optional Filament plugin integration.
- Private module scaffold and Git/module release documentation.
- A runtime guard for PHP 8.5, native GD/Mbstring, and the built-in OPcache
  extension.

### Changed

- Raised the minimum runtime to PHP 8.5 and the commercial-module platform
  contract to `2.0.0`; modules targeting Platform 1 must publish a compatible
  Platform 2 release before installation.
- Adopted the PHP 8.5 driver-specific `Pdo\Mysql` SSL option constants.
- Require native Mbstring for authentication normalization and bounded text
  processing instead of relying on a userland polyfill.
- Standardized contact, bug-reporting, security, and commercial support
  channels on `contact@douwyn.com` and `https://douwyn.com`.
- Pinned `js-yaml` to the patched 4.3 release line to address
  `GHSA-52cp-r559-cp3m` in the OpenAPI generation toolchain.
- Removed the commercial Ledger package and local path repository from the
  public root dependency graph; paid modules are installed only by authorized
  customer applications or private integration fixtures.

[Unreleased]: https://github.com/douwyncom/douwyn-starter-kit/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/douwyncom/douwyn-starter-kit/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/douwyncom/douwyn-starter-kit/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/douwyncom/douwyn-starter-kit/releases/tag/v1.0.0
