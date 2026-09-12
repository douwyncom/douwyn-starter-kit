# Contributing

Thank you for helping improve Douwyn Starter Kit. This public repository
accepts contributions to the open-source core and `packages/nuxt-api` only.
Do not submit source code, tests, documentation, credentials, or generated API
contracts from a commercial Douwyn module.

## Getting started

Follow the [installation guide](README.md#-installation), including database
setup, `APP_KEY` generation for the new checkout, and installation of both
Composer and Bun dependencies. The public tests use PDO SQLite in memory;
Swoole and private package credentials are not needed for that suite.

Fork the repository, create a descriptive branch from `main`, and open the pull
request against `main`. Avoid working directly on a release tag. Describe larger
behavior or contract changes in a
[GitHub issue](https://github.com/douwyncom/douwyn-starter-kit/issues) before
writing a large patch so maintainers can discuss the scope.

Participation follows the [Code of conduct](CODE_OF_CONDUCT.md). General usage
questions follow [SUPPORT.md](SUPPORT.md).

## Development workflow

1. Keep each pull request focused and add regression coverage for changed
   behavior. Include the observed problem, expected result, and reproduction
   steps; add screenshots for visible interface changes when useful.
2. Preserve backward compatibility for the public platform and HTTP API, or
   document the migration and version impact. See the
   [platform contract](docs/platform-contract.md).
3. Update affected documentation and both `en` and `vi` translations. Keep
   translated operations guides synchronized with their English source.
4. Regenerate and review the public OpenAPI specification and Nuxt types with
   `bun run api:generate` when routes, request validation, or response resources
   change. Generate them with no commercial modules installed.
5. Run the relevant quality checks before opening the pull request:

   ```bash
   composer validate --strict
   composer check-platform-reqs
   composer check:runtime
   composer check:public-boundary
   vendor/bin/pint --test
   composer test
   bun install --frozen-lockfile
   bun run build
   bun run nuxt-api:typecheck
   bun run nuxt-api:test
   bun run nuxt-api:build
   ```

Use `vendor/bin/pint` to fix PHP formatting. Commit intentional source,
documentation, and lock-file changes; keep local environment files, build
output, `vendor/`, `node_modules/`, and commercial integration artifacts out of
the pull request. Never include access tokens, encryption keys, or real user
data in examples or fixtures.

Record user-visible fixes and changes under `Unreleased` in
[CHANGELOG.md](CHANGELOG.md), including any upgrade action. Maintainers assign
release versions and tags after review and required checks; see the
[release workflow](docs/git-release.md). Contributors do not need to publish a
release to submit a patch.

## Contribution license

Intentionally submitted and accepted contributions are provided under the
Apache License 2.0, as described by section 5 of that license. By submitting,
you confirm that you have the right to provide the contribution under those
terms. Maintainers will not merge a submission marked as not being a
contribution.

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).
