# SDK, CLI and add-on test harness (18.05)

Forwext 18.05 provides development-only tooling for creating, checking, installing into a local workspace, upgrading and building third-party add-ons. None of these tools changes the production cPanel runtime contract.

## CLI foundation

`tools/forwext.php` uses a small typed command dispatcher. Commands return explicit stdout/stderr and exit codes, which keeps them testable without shelling out from unit tests.

The tools namespace is registered only through Composer `autoload-dev`.

## Scaffold and generators

`addon:create Vendor/AddOn [version]`:

- validates the canonical add-on id and SemVer;
- creates `addons/Vendor/AddOn/`;
- generates an `addon.json` that is immediately parsed by the production `AddonManifest`;
- generates PHP 8.4 Composer metadata and a strict-types extension entry;
- refuses to overwrite an existing add-on.

`make:class`, `make:service` and `make:entity` generate strict-types PHP inside the add-on namespace and refuse existing target files.

## Developer mode and workspace install/upgrade

`DeveloperMode` stores an atomic, permission-restricted marker under `storage/dev/`.

It is intentionally a **tool-only switch**. Production HTTP code, `PermissionEngine`, ACP authorization and `AddonLifecycleService` do not consume the marker.

`addon:install` and `addon:upgrade` require developer mode and operate only on the checked-out `addons/` workspace. Sources are bounded by the same package-scale limits, reject symlinks/unsupported entries and are staged before atomic replacement. Upgrade requires a strictly newer add-on version.

These commands do not silently mark an add-on enabled in the production database.

## Compatibility checker

`AddonCompatibilityChecker` reuses `AddonPackageInspector` and verifies:

- canonical package/manifest identity and package bounds;
- the add-on minimum Forwext version against the current checkout;
- PHP parse correctness;
- `declare(strict_types=1)`;
- source/migration namespaces remain under the declared add-on namespace;
- selected high-risk evaluation/process-launch primitives are rejected through PHP token analysis.

A bundled `vendor/` tree is surfaced as a warning for license and PHP-baseline review rather than silently trusted.

## Build

`addon:build` runs compatibility checks first and produces a deterministic ZIP.

The internal ZIP32 writer:

- orders entries deterministically;
- rejects absolute/traversal/backslash archive paths;
- writes stored entries with CRC32;
- does not require ext-zip.

The package includes `forwext-build.json` with schema, id, version, source tree checksum and per-file SHA-256 hashes. Tests and IDE-only `.forwext/` output are excluded from distributable add-on packages.

## IDE and static analysis

`ide:types` writes an IDE-only type bridge under the add-on's `.forwext/` directory. It references the real backend and UI registration types but is not loaded by production runtime.

`phpstan.addon.neon.dist` provides a max-level static-analysis profile for add-on source while excluding bundled vendor code and generated IDE metadata.

## Verification and deployment

18.05 requires no database migration. PHPUnit exercises the scaffold, generators, CLI behavior, developer-mode marker, compatibility failure paths, deterministic ZIP output and IDE bridge on both PHP 8.4 and 8.5.

Standard Forwext hosting remains independent from these development tools and does not gain a Composer/Node/npm/SSH requirement.
