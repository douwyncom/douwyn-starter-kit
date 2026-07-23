# Commercial module boundary

Douwyn Starter Kit uses an open-core distribution model:

- this repository is the public, independently installable core and is
  licensed under Apache-2.0;
- `packages/nuxt-api` is part of that public core and uses Apache-2.0;
- optional paid modules are separate Composer packages distributed from
  private repositories or a private Composer registry; and
- each paid module remains subject to its own commercial licence and support
  agreement. The root Apache-2.0 licence does not grant access to or rights in
  the module's separately authored proprietary code. Any Apache-licensed or
  third-party component copied into a module retains its original licence and
  notice requirements.

## Repository rules

The root `modules/` directory is reserved as a local integration workspace and
is intentionally ignored by Git. It may contain symlinked or copied commercial
packages during private development, but no file below it may be committed to
the public repository.

The public root `composer.json` and `composer.lock` must not require or resolve
a commercial package. Generated OpenAPI documents, TypeScript declarations,
fixtures, snapshots, and public tests must also be generated from the core
without a paid module installed. This keeps a fresh public clone installable,
testable, and releasable without private credentials.

CI enforces the boundary by rejecting tracked files below `modules/` and
commercial package requirements in the root Composer manifest. Before a public
release, also search generated artifacts for private route names, schemas,
namespaces, and package names.

## Installing a purchased module

Customers receive the private repository or registry endpoint and read-only
credentials through the purchase channel. Store credentials in global
Composer authentication, a deployment secret, or `COMPOSER_AUTH`; never put
them in this repository, `.env.example`, `composer.json`, or `auth.json`.

A customer application may add its authorized repository and require the
purchased package in that application's own `composer.json`. That application
lock file is private to the customer; it is not copied back into the public
starter-kit repository.

For implementation and release details, see
[Private module development](module-development.md) and
[Git and release workflow](git-release.md).

For purchase, licensing, registry access, or support, email
**[contact@douwyn.com](mailto:contact@douwyn.com)** or visit
**[https://douwyn.com](https://douwyn.com)**.
