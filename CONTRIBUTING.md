# Contributing

Thank you for helping improve Douwyn Starter Kit. This public repository
accepts contributions to the open-source core and `packages/nuxt-api` only.
Do not submit source code, tests, documentation, credentials, or generated API
contracts from a commercial Douwyn module.

## Development workflow

1. Contact **[contact@douwyn.com](mailto:contact@douwyn.com)** or visit
   **[https://douwyn.com](https://douwyn.com)** about significant behavior or
   contract changes before writing a large patch.
2. Keep each pull request focused and include tests for changed behavior.
3. Preserve backward compatibility for the public platform and HTTP API, or
   document the migration and version impact.
4. Run the relevant quality checks before opening the pull request:

   ```bash
   composer validate --strict
   composer check:public-boundary
   vendor/bin/pint --test
   php artisan test --compact
   bun install --frozen-lockfile
   bun run build
   bun run nuxt-api:typecheck
   bun run nuxt-api:test
   bun run nuxt-api:build
   ```

Intentionally submitted and accepted contributions are provided under the
Apache License 2.0, as described by section 5 of that license. By submitting,
you confirm that you have the right to provide the contribution under those
terms. Maintainers will not merge a submission marked as not being a
contribution.

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).
