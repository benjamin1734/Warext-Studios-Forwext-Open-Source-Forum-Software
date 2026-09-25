# Third-party add-on manifest, package and lifecycle (18.01)

Forwext third-party add-ons use a separate extension domain from first-party modules. Add-ons may use only the documented public extension platform; they do not patch `core/` or `app/`.

## Canonical identity and package location

An add-on ID is canonical `Vendor/AddOn`. Both segments are bounded ASCII identifiers and may not contain path separators, dots, traversal tokens or whitespace.

The extracted package root is:

`addons/Vendor/AddOn/`

and must contain a regular `addon.json`. The package inspector:

- resolves the configured add-on root and rejects packages outside it,
- requires the filesystem path to match the manifest ID exactly,
- rejects symbolic links and non-regular filesystem entries,
- applies entry, per-file and total-size limits,
- bounds the manifest to 128 KiB,
- computes a deterministic SHA-256 tree checksum.

Package signatures are deliberately not part of 18.01; signing and official signature policy belong to roadmap step 18.06.

## Manifest v1

```json
{
  "id": "Acme/Demo",
  "version": "1.2.3",
  "title": "Demo",
  "description": "Example extension",
  "requires": {
    "forwext": "1.0.0",
    "addons": {
      "Acme/Base": "^1.0.0"
    }
  },
  "conflicts": {
    "addons": {
      "Acme/Legacy": "<2.0.0"
    }
  },
  "data_retention": "retain_only"
}
```

Unknown manifest keys fail closed. Add-on versions use SemVer. Add-on constraints support exact versions, `>`, `>=`, `<`, `<=`, caret, tilde and whitespace-separated AND clauses. The `forwext` requirement is the minimum compatible Forwext version.

`data_retention` is either:

- `retain_only`: uninstall may only retain add-on-owned data;
- `purge_supported`: delete-data uninstall is allowed only when a safe runtime data purger is registered.

A manifest declaration never grants purge ability by itself.

## Lifecycle

Lifecycle states are `enabled`, `disabled` and `uninstalled`.

- install validates Forwext compatibility, requirements, conflicts and cycles, then enters `disabled`;
- enable requires compatible required add-ons to be enabled and rejects active conflicts;
- disable rejects the transition while an enabled dependent still requires the add-on;
- upgrade requires the add-on to be disabled and the package version to increase;
- uninstall requires the add-on to be disabled and all dependents to be uninstalled first;
- keep-data uninstall records `retained`;
- delete-data uninstall requires both `purge_supported` and a registered `AddonDataPurger`, otherwise it fails closed.

Backend capability install/upgrade migrations and extension hooks are layered on this lifecycle in later roadmap steps. The state machine, compatibility graph, package validation, retention contract and persistence introduced here are authoritative foundations rather than UI-only checks.

## Permission and audit

Lifecycle mutations require both `acp.access` and `addon.manage`. Only the administrator permission template receives `addon.manage=allow` by default.

All lifecycle state writes are wrapped by the common Administration audit recorder. Audit snapshots contain ID, version, lifecycle state, data state and package checksum; package contents and secrets are not copied into audit events.

## Persistence

`forwext_addons` stores the normalized manifest, installed version, state, data state and package checksum.

`forwext_addon_relations` stores normalized requires/conflicts edges and version constraints for diagnostics and later ACP/dependency tooling.

The shared migration engine already supports `addon` owner scope. Canonical `Vendor/AddOn` owner names are accepted while existing legacy owner names remain readable.

No Composer/npm/Node/Redis/Docker/Supervisor dependency is introduced for the minimum cPanel runtime.
