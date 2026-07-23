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

`git check-ignore` must report the `/modules/` rule. Both `git ls-files`
commands above must print nothing: commercial module sources and the private
Ledger integration suite must not be tracked. Review `git status` before the
first commit. `.env`, `auth.json`, `vendor`, `node_modules`, IDE state, runtime
storage, Vite output, and Composer-published Filament assets must not be
committed.

Create the GitHub repository without initializing a README, licence, or
`.gitignore`; those files already belong to this checkout. Never commit or paste
a personal access token into the remote URL. After the first push, wait for the
quality workflow to pass and inspect a fresh clone before making the first
release.

Create the root remote repository as **public**. Protect `main`, require the
quality workflow, prevent force pushes, and require review for changes to the
platform namespace, Composer manifests, workflows, licence files, and release
documentation. Commercial module repositories remain private.

After the first workflow run, create a branch ruleset for `main`: require pull
requests and passing quality checks, require resolved conversations, restrict
deletion, and block force pushes. A solo maintainer can start with zero required
approvals and add a deliberate bypass; require at least one approval when a
second maintainer is available. Create a separate tag ruleset for `v*` that
restricts updates and deletion. Enable release immutability before publishing
the first stable GitHub Release.

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

The first application release can use `v1.0.0`. Application tags, the platform
capability, HTTP API, Nuxt package, and private modules have independent version
streams; do not force their numbers to match. Update `CHANGELOG.md`, run the
same commands as CI, push the release commit, and wait for `main` CI to pass
before creating an annotated or signed tag:

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

git switch main
git pull --ff-only
git tag -a v1.0.0 -m "Douwyn Starter Kit v1.0.0"
git push origin v1.0.0
```

Use `git tag -s` instead of `-a` when release signing is configured. A Git tag
is the immutable release input; a GitHub Release adds notes and downloadable
metadata but must not replace the tag.

Wait for the tag workflow to pass, then create a draft GitHub Release from the
existing tag. With GitHub CLI:

```bash
gh release create v1.0.0 \
  --verify-tag \
  --generate-notes \
  --title "v1.0.0 — Initial public release" \
  --draft

gh release edit v1.0.0 --draft=false
```

Review generated notes before publishing. Push the intended tag explicitly;
avoid `git push --tags`, which may publish unrelated local tags.

Before every release:

1. update `CHANGELOG.md` and upgrade notes;
2. verify `Platform::VERSION` equals the Composer capability version;
3. verify no `modules/**` or private integration files are tracked;
4. verify root Composer manifests and generated OpenAPI/Nuxt artifacts contain
   no commercial package, route, schema, or namespace;
5. regenerate the public API contract without paid modules installed and fail
   on an unexpected artifact diff;
6. run standard, Nuxt, and distributed PostgreSQL/Redis tests;
7. test supported paid modules in a separate private consumer fixture;
8. review migrations, public contracts, security changes, licences, notices,
   and third-party asset rights;
9. tag only the public-core commit that passed CI;
10. install and test a GitHub archive or fresh clone so release consumers do
    not depend on ignored local files.

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
