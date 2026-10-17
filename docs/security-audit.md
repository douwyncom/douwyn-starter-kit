# Dependency audit policy

Run `composer audit --locked` and `bun run audit:js` before every release.
`bun audit` remains available to display the unfiltered findings. A passing
policy check is **not** a claim that the dependency tree has zero advisories.

## Temporary development-tool exceptions

Approved by the maintainer on 2026-10-17; review and expiry: **2026-11-16 UTC**.
Upstream had no patched release at the approval date:

| Dependency | Advisory | Development path / limitation |
| --- | --- | --- |
| `braces@3.0.3` | [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) | Nuxt → Nitro → Globby/Fast-glob → Micromatch. Recursive glob parsing can exhaust the stack. Only trusted repository/build patterns may be used. |
| `node-forge@1.4.0` | [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv) | Nuxt CLI/Nitro → Listhen, used for local HTTPS certificate generation. Do not use this toolchain to verify untrusted RSA signatures or certificates. |

These are development dependencies of the reference Nuxt package, not the
Laravel backend or its deployed static admin assets. The shipped Nuxt client
does not bundle them. Nuxt applications consuming the client must separately
audit their own Nuxt/Nitro runtime and build dependencies; this exception is
not inherited by those applications. Do not expose development servers to
untrusted networks, accept arbitrary build/glob patterns, or run CI against
untrusted code with credentials.

The policy script calls the real Bun audit endpoint and fails closed on
network/JSON errors. It permits only the exact package/advisory/version pairs,
only in the Nuxt tool dependency graph, and never when reachable through a root
or workspace production dependency (including optional and transitive peer
edges). All other severities/advisories remain blocking. Expired exceptions,
changed versions, or exceptions no longer reported by upstream fail the gate
and require review/removal. `bun run audit:js:test` verifies these guards.

Replace the affected dependencies as soon as compatible patches are available,
remove their entries from `scripts/audit-javascript.mjs`, update the lockfile,
and run the complete Quality suite. Do not automatically extend the deadline.
