# Git and release workflow

## Canonical names

Keep these names stable:

| Artifact | Recommended name |
| --- | --- |
| Product | Douwyn Starter Kit |
| Root Git repository slug | `douwyn-starter-kit` |
| Root Composer package | `douwyncom/douwyn-starter-kit` |
| Platform capability | `douwyncom/starter-kit-platform` |
| Module Git repository | `starter-kit-<slug>` |
| Module Composer package | `douwyncom/starter-kit-<slug>` |

The GitHub owner and Composer vendor do not have to be identical. Choose the
company GitHub organization that will own access control, then keep it stable.
The examples below use `<github-owner>` so publishing cannot accidentally target
the wrong account.

## Publish this checkout for the first time

This checkout may be distributed without `.git`. Initialize it only after the
quality suite is green and the public/private boundary has been checked:

```bash
git init -b main
composer check:public-boundary
git check-ignore -v modules/starter-kit-ledger/composer.json
git check-ignore -q scripts/check-public-boundary.php && \
  echo "ERROR: public-boundary script is ignored" && exit 1 || true
git status --short --untracked-files=all
git check-ignore -v packages/nuxt-api/node_modules
git add .
git status --short
git ls-files --error-unmatch scripts/check-public-boundary.php
git ls-files -- 'modules/**'
git ls-files -- \
  tests/Feature/LedgerCoreIntegrationTest.php \
  tests/Feature/LedgerModuleDocumentationTest.php \
  tests/Feature/LedgerModuleIntegrationTest.php \
  tests/Feature/LedgerUserProvisioningFilamentTest.php \
  tests/Integration/DistributedLedgerTest.php \
  tests/Support/distributed_ledger_worker.php
git ls-files -ci --exclude-standard
git commit -m "chore: establish Douwyn Starter Kit platform v1"
git remote add origin git@github.com:<github-owner>/douwyn-starter-kit.git
git remote -v
git push -u origin main
```

`git check-ignore` must report the `/modules/` rule. The module/private-test `git ls-files` commands above must print nothing: commercial module sources and the private
Ledger integration suite must not be tracked. Review `git status` before the
first commit. `.env`, `auth.json`, `vendor`, `node_modules`, IDE state, runtime
storage, Vite output, and Composer-published Filament assets must not be
committed.

Create the GitHub repository without initializing a README, licence, or
`.gitignore`; those files already belong to this checkout. Never commit or paste
a personal access token into the remote URL. After the first push, inspect a
fresh clone and run the local quality suite before making the first release.

Create the root remote repository as **public**. Protect `main`, prevent force
pushes, and require review for changes to the platform namespace, Composer
manifests, licence files, and release documentation. Commercial module
repositories remain private.

After the initial push, create a branch ruleset for `main`: require pull
requests, require resolved conversations, restrict deletion, and block force
pushes. A solo maintainer can start with zero required approvals and add a
deliberate bypass; require at least one approval when a second maintainer is
available. Create a separate tag ruleset for `v*` that restricts updates and
deletion. Enable release immutability before publishing the first stable GitHub
Release.

If an existing Git repository already tracks ignored module files, `.gitignore`
does not untrack them. Remove them from the index while retaining the local
files, then review and commit the deletion:

```bash
git rm -r --cached modules
git rm --cached \
  tests/Feature/LedgerCoreIntegrationTest.php \
  tests/Feature/LedgerModuleDocumentationTest.php \
  tests/Feature/LedgerModuleIntegrationTest.php \
  tests/Feature/LedgerUserProvisioningFilamentTest.php \
  tests/Integration/DistributedLedgerTest.php \
  tests/Support/distributed_ledger_worker.php
```

If private code or credentials have already reached a remote, removing the
current files is not sufficient. Stop the public release, rewrite the affected
history with a reviewed history-cleaning procedure, rotate every exposed
credential, and have another maintainer verify the cleaned clone before making
the repository public.

## Create a release

Application tags, the platform capability, HTTP API, Nuxt package, and private
modules have independent version streams. Choose the next application SemVer
from existing remote tags; never reuse or move a published tag. The current
application release is `v1.2.0` with Platform `2.2.0`.

Prepare changes on a focused branch and update `CHANGELOG.md` with the actual
release date, behavior changes, security fixes, and upgrade notes. Do not claim
that an unreleased version was already published. Merge the reviewed pull
request only after its `Quality` workflow passes. Maintainers working directly
on `main` must complete the same local and remote checks before tagging.

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer check:runtime
composer check:public-boundary
composer audit --locked
vendor/bin/pint --test
php artisan test --compact
bun install --frozen-lockfile
bun audit
bun run build
bun run nuxt-api:typecheck
bun run nuxt-api:test
bun run nuxt-api:build
bun run api:generate
git diff --exit-code -- packages/nuxt-api/openapi.json packages/nuxt-api/src/openapi.ts
```

Regenerate API artifacts in a checkout without private modules. If the API
intentionally changes, review and commit the generated diff before running the
last check again. Do not commit local absolute server URLs.

The public PHP suite uses SQLite. PostgreSQL/MySQL concurrency, Redis locks,
paid-module integration, and real Swoole worker behavior require separate
consumer/deployment fixtures; do not report them as tested by the public suite.
Run `composer check:octane` on the deployment runtime if using Octane, then
follow its [load and isolation checks](octane-swoole.md).

Before publishing, test a clean clone or source archive with new dependency
installs, `.env.example`, a disposable database, and a newly generated test key.
Verify migrations, the starter-kit installer, public tests, frontend build,
Nuxt package build, and API generation. Keep the clone outside the working
application so no development database or key can be changed.

After committing and pushing the reviewed changes, wait for the commit's
GitHub Actions quality job to pass. Then tag that exact commit. For example:

```bash
git switch main
git pull --ff-only
git status --short
git tag -a v1.2.0 -m "Douwyn Starter Kit v1.2.0"
git push origin v1.2.0
```

Replace `v1.2.0` with the new, unused version when making the next release.
Use `git tag -s` when signing is configured. Push only the intended tag;
avoid `git push --tags` and never force-push release tags.

The `Quality` workflow reruns checks for `v*` tags. Only after those checks
succeed does its release job publish a GitHub Release from the matching
`CHANGELOG.md` section. Its write permission is limited to that release job;
pull requests run with read-only repository permissions. A failed check or a
missing changelog section prevents publication. Existing releases are left
unchanged on reruns.

Verify the published release URL, tag commit, and workflow result. If the
release job fails after quality checks pass, fix the external cause and rerun
the job. If code changes are needed, publish a new version; do not move a tag.
GitHub creates source archives automatically. CI publication does not publish
`@douwyn/nuxt-api` to npm or any commercial module package.

Before every release:

1. verify `Platform::VERSION` equals the Composer capability version;
2. verify the public-boundary command rejects tracked private source and
   private dependencies or routes in the public artifacts;
3. check that `.env`, credentials, runtime storage, and generated frontend
   assets are excluded from Git;
4. review migrations, contracts, licences, notices, and third-party asset rights;
5. record which runtime, database, and concurrency checks were actually run;
6. tag only the commit that passed local and remote quality checks.

## Customer application repositories

A customer starts from a public tagged starter-kit release, then owns a
separate application repository. Keeping the starter source as `upstream`
makes future updates auditable:

```bash
git clone https://github.com/<github-owner>/douwyn-starter-kit.git customer-app
cd customer-app
git remote rename origin upstream
git remote add origin git@github.com:<customer-owner>/customer-app.git
git push -u origin main
```

Upgrade from an explicit upstream tag instead of merging unreleased `main`:

```bash
git fetch upstream --tags
git merge --no-ff v1.1.0
```

Customer application code may change freely under Apache-2.0. When commercial
Douwyn modules are installed, the customer's private `composer.json` must
preserve the root platform name and capability and must follow each module's
commercial licence.

## Private module distribution

Start with one private Git repository and SemVer tags per module. The
customer application can register each authorized `vcs` URL. The public
starter-kit root must not register or require those packages. Once several
modules exist, move repository metadata to a private Composer registry so
customers receive only the catalogue and versions their credentials may access.

Store credentials outside Git:

- local development: SSH keys or a read-only token in global Composer auth;
- CI/deploy: a short-lived installation token or secret `COMPOSER_AUTH`;
- customer: read-only access, scoped to purchased modules;
- maintainers: write access only where release duties require it.

Revoking repository credentials is the real distribution lock. The Composer
platform marker prevents accidental installation into an unsupported host and
the runtime host check fails fast when the root identity is changed.
