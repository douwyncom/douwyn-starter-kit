# Douwyn Starter Kit module template

Copy this directory into a separate private Git repository, then replace every
`Example` / `example` occurrence with the module name. Keep the Composer
platform requirement and extend `ModuleServiceProvider`; those two pieces make
the package installable only in a compatible Douwyn Starter Kit host.
The maintained scaffold targets the current Platform `^2.2` contracts.

Do not add a hard-coded `version` field to `composer.json`. Composer derives the
package version from Git tags such as `v0.1.0`.

Develop and test the module through a Douwyn Starter Kit checkout using a
Composer `path` repository. See `docs/module-development.md` in the starter-kit.
