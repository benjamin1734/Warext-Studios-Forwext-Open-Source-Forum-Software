# tools

Development, validation, release and repository-maintenance tooling.

The tools tree is development-only. Production cPanel packages continue to ship their required vendor/build output and do **not** require Composer, npm, Node.js, SSH, Redis, Docker or Supervisor at runtime.

## Developer CLI

Run from a development checkout with development dependencies installed:

```bash
php tools/forwext.php help
```

18.05 commands:

- `addon:create Vendor/AddOn [version]` — create a validated add-on scaffold without overwriting an existing add-on.
- `make:class Vendor/AddOn ClassName`
- `make:service Vendor/AddOn ClassName`
- `make:entity Vendor/AddOn ClassName`
- `dev:enable`, `dev:disable`, `dev:status` — control developer-workspace mutation mode only.
- `addon:install /path/to/addon` — stage a source add-on into the local development workspace; requires developer mode.
- `addon:upgrade /path/to/addon` — replace a development-workspace add-on only with a newer version; requires developer mode.
- `addon:check Vendor/AddOn` — run manifest/version/PHP/namespace/security compatibility checks.
- `addon:build Vendor/AddOn [output.zip]` — run compatibility checks and build a deterministic ZIP with SHA-256 inventory.
- `ide:types Vendor/AddOn` — generate IDE-only typed backend/UI registration metadata.

## Security boundary

Developer mode is **not** a Forwext runtime mode and is not consulted by `PermissionEngine`, ACP authorization, HTTP routes or the persisted add-on lifecycle. It only gates explicit local CLI workspace mutations.

The compatibility checker reuses the canonical add-on package inspector, validates the current Forwext requirement, parses PHP source, enforces `declare(strict_types=1)`, checks add-on namespaces and rejects selected high-risk process/evaluation primitives.

Generated ZIP entry paths are validated against traversal/absolute-path forms. Packaging is deterministic and implemented in PHP so ext-zip is not a development requirement for `addon:build`.

## Static analysis

`phpstan.addon.neon.dist` provides the repository add-on analysis profile. Add-on `vendor/` and generated `.forwext/` IDE metadata are excluded from analysis input; add-on source remains at maximum PHPStan level.

The normal project QA commands in `composer.json` remain authoritative for the Forwext repository itself.
