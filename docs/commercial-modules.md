# Commercial modules

[Vietnamese translation](commercial-modules.vi.md)

This English document is the canonical source for the commercial-module model. The Vietnamese document
is a translation. When the two differ, update this document first and then synchronize the translation.

## Open-source core and paid add-ons

Douwyn Starter Kit uses an open-core distribution model:

- this repository is the public, independently installable core and is licensed under Apache-2.0;
- `packages/nuxt-api` is part of that public core and also uses Apache-2.0;
- optional paid modules are separate Composer packages distributed from private repositories or a
  private Composer registry; and
- each paid module is governed by its own commercial licence and support agreement.

The public core must remain useful, installable, testable, and releasable without a paid module or private
registry credential. Buying a module grants only the rights stated in its applicable commercial
agreement; it does not change the licence of the public core.

Only separately authored Douwyn module code may be offered under proprietary terms. Code, assets, or
documentation copied from the Apache-2.0 core or a third-party dependency retain their original licence
and notice obligations. Keep provenance clear and obtain legal review before the first sale.

## Repository topology

Use one private source repository for each independently sold module:

```text
Public
└── douwyn-starter-kit                 Apache-2.0

Private product repositories
├── starter-kit-ledger                 proprietary
├── starter-kit-payment                proprietary
├── starter-kit-telegram               proprietary
└── starter-kit-<module>               proprietary

Private internal repository
└── commercial-integration-fixture     cross-repository release CI
```

One repository per product/SKU keeps versioning, pricing, access, releases, support, and revocation
independent. Do not give a customer access to a repository containing modules they did not purchase.

The root `modules/` directory in this checkout is an ignored local integration workspace. It may contain
temporary copies or separate Git checkouts during development, but the public repository must not track
anything below it. The authoritative source and release tags for a commercial module belong to that
module's own private repository.

Do not introduce a shared commercial package until multiple modules genuinely need the same maintained
implementation. If one is introduced later, it must have its own licence, release process, compatibility
policy, and customer entitlement.

## Platform compatibility boundary

The starter kit provides the virtual Composer capability
`douwyncom/starter-kit-platform`. A commercial module requires a compatible capability and registers the
same runtime constraint through its module manifest.

For example:

```json
{
    "name": "douwyncom/starter-kit-ledger",
    "license": "proprietary",
    "require": {
        "douwyncom/starter-kit-platform": "^2.0"
    }
}
```

This is a compatibility boundary, not digital-rights management. Commercial access is enforced by the
private repository or registry entitlement and the purchase agreement.

Apply these compatibility rules:

- keep each module's version independent from the starter-kit release and Platform capability version;
- require the oldest Platform minor whose contracts the module actually uses;
- keep the Composer constraint and runtime module-manifest constraint identical;
- test the lowest declared Platform version and the latest supported stable version;
- treat a breaking Platform contract as a new Platform major;
- treat a breaking public module API as a new module major; and
- never move or overwrite a released tag.

Modules must use public Platform contracts instead of importing application classes under `App\` or
depending directly on the host user table. Module-owned records may keep canonical lowercase UUIDv7
logical references such as `user_uuid`, but cross-database ownership must be resolved through the
Platform boundary.

See [Platform contract](platform-contract.md) and
[Private module development](module-development.md).

## Package baseline

Every commercial module repository should contain:

```text
composer.json
LICENSE
LICENSE.vi.md
README.md
README.vi.md
SECURITY.md
SECURITY.vi.md
SUPPORT.md
SUPPORT.vi.md
CHANGELOG.md
config/
database/
docs/
lang/
routes/
src/
tests/
```

Use `"license": "proprietary"` in `composer.json`; Composer documents this identifier for closed-source
software in its [package schema](https://getcomposer.org/doc/04-schema.md#license).

The English `LICENSE`, `README.md`, `SECURITY.md`, `SUPPORT.md`, and English documentation are canonical.
Vietnamese files are translations and must preserve the same technical commands, supported versions,
restrictions, security process, and support boundary.

The package `LICENSE` is a product notice, not a substitute for a reviewed Master Commercial
Agreement/EULA and customer-specific order form. The exact contracting entity must be consistent across
the package notice, checkout, invoice, order form, support channel, and repository metadata.

When a module bundles third-party source, assets, fonts, fixtures, or generated content, include the
required third-party licences and notices. Do not relabel open-source material as proprietary.

## Recommended commercial model

The following is a product recommendation, not binding legal text:

- one licence covers one legal entity and the purchased number of production products;
- development, staging, CI, disaster recovery, and backups for those products do not consume additional
  product licences;
- authorized contractors may access the module only for the licensed customer's work and under
  confidentiality obligations;
- customers may modify the delivered source for internal use;
- customers may continue using versions received while properly licensed;
- the initial purchase includes a defined update and support period, such as 12 months; and
- public distribution, resale, sublicensing, and source redistribution are prohibited unless a separate
  agreement permits them.

Keep the first catalogue simple:

| Tier | Intended scope |
| --- | --- |
| Standard | One legal entity and one production product |
| Agency | A defined number of separately registered client products |
| Enterprise | Negotiated entities, products, support, and compliance terms |
| OEM/Redistribution | On-premise delivery, source handoff, resale, or redistribution rights |

Avoid licensing by server or domain unless the product genuinely requires it. Autoscaling, preview
environments, staging domains, and disaster recovery make those units difficult to administer.

The contract must distinguish a perpetual right to use already received versions from a subscription
whose use right ends. It must also distinguish update expiry from full licence termination. Have qualified
legal counsel review the final terms, governing law, warranty, liability, privacy, and language precedence.

## Delivery and entitlement

The recommended delivery path is:

```text
Private GitHub repository
        -> immutable release tag
Private Packagist for Vendors
        -> customer-specific package entitlement
Customer Composer project
```

GitHub is the authoritative source and tag origin. Customers normally receive release archives through
Private Packagist rather than repository history. Private Packagist for Vendors supports customer-specific
repository URLs and tokens, package/version constraints, minimum stability, publication-date limits, and
source-URL delivery controls. Follow its
[official vendor setup](https://packagist.com/docs/setup-vendor).

For a small pilot, direct private-Git VCS access can be managed manually. Move to a customer-aware Composer
registry before access administration becomes error-prone or customers would otherwise see repositories
they did not purchase.

Entitlement records belong in a private sales/licensing system, not in module source:

```text
customers
commercial_products
orders
licenses
license_entitlements
support_contracts
audit_logs
```

Use canonical UUIDv7 primary keys following the project's `_uuid` convention, for example
`customer_uuid`, `product_uuid`, `order_uuid`, `license_uuid`, and `entitlement_uuid`.

An entitlement should record:

- customer and product UUIDs;
- Composer package name;
- allowed version constraint, such as `^1.0`;
- permitted production-product count or tier;
- `updates_until` and `support_until`;
- status such as `active`, `suspended`, `expired`, or `revoked`;
- external registry customer/package identifiers; and
- the accepted commercial-terms version.

Do not store a customer registry token in plaintext when the registry already owns that secret. Store an
external identifier and auditable lifecycle events instead.

## Customer lifecycle

### Purchase and onboarding

1. The customer selects a module and licence tier.
2. The customer accepts the reviewed Master Terms and order form.
3. Payment is confirmed.
4. Create the customer, licence, and package entitlement.
5. Grant only the purchased package, stable releases, the purchased version range, and the contractual
   publication cutoff.
6. Send the portal-generated Composer repository and authentication instructions through an authenticated
   channel.
7. Test a clean installation using that exact customer entitlement.
8. Record the order reference, support channel, and accepted terms version.

The first customers can be onboarded manually. Automate payment webhooks, entitlement changes, renewal
notifications, and registry API calls only after the manual process is stable and auditable.

### Installation

Customers add the authorized repository to their private application and store authentication in a
Composer auth store or protected CI secret. The customer application commits its own `composer.json` and
reviewed `composer.lock`.

Never put a token in the public starter kit, `.env.example`, module source, screenshots, support tickets,
or a Git URL. Repository definitions are root-only Composer configuration, so they belong to the
customer's application rather than a dependency package.

### Updates and renewal

Resolve updates in a controlled development/build environment, test them, and deploy the reviewed lock
file with `composer install`. Production must not run an unconstrained `composer update`.

For a perpetual-use licence with time-limited updates, expiry should set a publication-date/version cutoff
while preserving access to previously entitled releases. Renewal extends `updates_until` and
`support_until`; it does not require a new package identity.

A new major may require a paid upgrade. Expand the entitlement version constraint only after the purchase
and compatibility migration are approved.

### Suspension and termination

Do not revoke previously entitled releases merely because an update period ended. Remove the complete
package entitlement and revoke credentials only when the agreement permits full termination, such as a
refund, cancellation of a subscription use right, material breach, or credential compromise.

Revocation prevents future authorized downloads. It cannot erase source or archives already delivered to
a customer. Contract terms and access control, not a runtime kill switch, are the primary enforcement
mechanisms.

## Runtime licensing

Do not make the first module release depend on a call-home licence server. PHP source delivered through
Composer remains readable and modifiable, while a licensing outage could stop a customer's production
application.

If runtime licence verification is introduced later, specify privacy, stored identifiers, timeout,
offline/grace behavior, incident recovery, and what happens when the service is unavailable. Financial,
authentication, or other critical modules must never corrupt or delete customer data because an
entitlement check failed.

## Security and support

Each module must ship its own `SECURITY.md` because registry customers may not have access to the source
repository. The policy should identify:

- supported versions and their relationship to the maintenance agreement;
- a private vulnerability-reporting channel;
- the diagnostic information to include and data that must never be sent;
- coordinated disclosure and security-release handling; and
- the boundary between security response and general integration support.

Do not promise an acknowledgement or remediation SLA unless the commercial support operation can meet it.
The applicable customer agreement controls paid support scope and response targets.

## Development and release gates

Every module release CI must:

1. check out the private module and a clean supported starter-kit fixture;
2. install the module through a Composer path repository;
3. verify Composer and runtime Platform constraints agree;
4. run package tests, the full host suite, formatting, dependency audit, and secret scan;
5. test every advertised database/cache/queue topology;
6. test fresh install, supported upgrades, configuration cache, and package removal without deleting
   customer data;
7. validate Scramble/API documentation without writing private artifacts into the public core;
8. install the candidate tag/archive as a customer would; and
9. create the immutable release tag only after required branch CI passes.

Test the databases and infrastructure that marketing claims to support. Do not advertise “all database
servers” from an abstraction alone.

## Public repository rules

The public root `composer.json` and `composer.lock` must not require or resolve a commercial package.
Generated OpenAPI documents, TypeScript declarations, fixtures, snapshots, and public tests must also be
generated from the core without a paid module installed.

`composer check:public-boundary` rejects tracked files below `modules/` and commercial package
requirements in the root Composer manifest. Run it before every public push or release. Its generated
artifact scan rejects private module namespaces and API paths outside the explicitly reviewed core
prefixes, so a newly introduced public-core prefix must be reviewed and added deliberately.

Before publishing the public core:

- verify `git ls-files -- 'modules/**'` returns no files;
- regenerate public API/Nuxt artifacts without commercial packages;
- verify the public build and test suite need no private credentials; and
- test paid modules separately in the private integration fixture.

## Further documentation

- [Platform contract](platform-contract.md)
- [Private module development](module-development.md)
- [Git and release workflow](git-release.md)
- [Public security policy](../SECURITY.md)

For purchase, licensing, registry access, or support, email
**[contact@douwyn.com](mailto:contact@douwyn.com)** or visit
**[https://douwyn.com](https://douwyn.com)**.
