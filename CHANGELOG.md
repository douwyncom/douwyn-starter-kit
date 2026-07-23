# Changelog

All notable changes to Douwyn Starter Kit are documented here. Releases use
Semantic Versioning for the application; the platform, HTTP API, Nuxt client,
and private modules maintain their own compatibility versions.

## [Unreleased]

### Added

- Apache-2.0 licensing for the public core and official Nuxt API package.
- Community contribution, conduct, security, support, notice, and trademark
  policies for public-source development.
- Commercial-module boundary documentation plus Git and CI guards that reject
  private module sources and dependencies from the public repository.
- Platform 2 authenticated API middleware and configured user-model resolver.
- Shared `en`/`vi` locale resolution for web and API requests.
- Extensible API error-code and token-ability profile registries.
- Platform 2 compatibility capability and runtime host validation.
- Stable `Douwyn\StarterKit` contracts, module registry, and diagnostic command.
- Auto-discovered module provider with optional Filament plugin integration.
- Private module scaffold and Git/module release documentation.
- GitHub quality workflow for PHP, Nuxt, and distributed authentication tests.
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
