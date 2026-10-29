# Dependency audit policy

Run `composer audit --locked` and `bun run audit:js` before every release.
`bun audit` remains available to display the unfiltered findings. A passing
policy check is **not** a claim that the dependency tree has zero advisories.

## Current review (2026-10-29)

The previous `node-forge` and `braces` exceptions have been retired:

| Dependency | Advisory | Resolution |
| --- | --- | --- |
| `braces@3.0.3` | [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) | Upstream withdrew the advisory. This is not a claim that a patch was released. |
| `node-forge@1.4.0` | [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv) | Updated to 1.4.2; the old exception no longer applies. |

The maintainer approved one temporary development-tool exception on 2026-10-29:
`deepmerge@4.3.1`, reported for
[GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4), and has
no upstream patch as of this review. It is a low-severity prototype-poisoning
issue in the build dependency path Nuxt/Nitro and Unbuild → Rollup node-resolve
→ Deepmerge. The exception applies only to this exact advisory/package/version
at **low** severity and expires at **2026-11-16 00:00 UTC**. A higher reported
severity, a production dependency path, or any other finding remains blocking.
This exception accepts a limited build-tool risk; it does not fix the issue.
Build only trusted repository configuration and never pass user-supplied merge
objects to these tools. Remove the exception when a compatible patch is available;
do not extend its deadline automatically.

These are development dependencies of the reference Nuxt package, not the
Laravel backend or its deployed static admin assets. The shipped Nuxt client
does not bundle them. Nuxt applications consuming the client must separately
audit their own Nuxt/Nitro runtime and build dependencies; this exception is
not inherited by those applications. Do not expose development servers to
untrusted networks, accept arbitrary build/glob patterns, or run CI against
untrusted code with credentials.

## Gate behavior

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
