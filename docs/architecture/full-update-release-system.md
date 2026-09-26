# Full ZIP + differential update release system

Status: **Implemented and integrity-qualified for roadmap 20.02**

Forwext publishes two immutable artifacts for every release after an immediate predecessor exists:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

Development versions use the complete `VERSION` token in the same names. Both ZIP files are accompanied by SHA-256 files and are uploaded by the release workflow only after the complete quality matrix passes.

## Full package contract

The full ZIP is a clean-install package, not a source archive. CI verifies the built archive itself and fails when:

- the ZIP cannot be tested successfully;
- it does not contain exactly one application root;
- `VERSION`, `LICENSE`, `install.php`, `public/install.php` or bundled `vendor/autoload.php` is missing;
- packaged `VERSION` differs from the release version;
- an archive member uses an absolute/traversal path;
- generated configuration, master-key, installed-version or encrypted secret state leaks into the package;
- the external SHA-256 file does not match the ZIP.

The ordinary cPanel full package continues to include production PHP dependencies and does not require Composer/npm/Node/SSH on the target host.

## Differential update contract

The update ZIP is built by comparing the new full package with the **immediately preceding immutable GitHub Release full package**. Older legacy `install.zip` assets are read only as predecessor compatibility and are never produced again.

`update-manifest.json` contains:

- exact `source_version` and `target_version`;
- sorted unique `add`, `replace` and `delete` sets;
- protected `preserve` paths;
- migration files included in the changed payload;
- rebuild actions;
- `sha256` as checksum algorithm;
- an exact checksum map for every add/replace payload file.

CI opens the actual built update ZIP and verifies that:

1. source version equals the release workflow's immediate predecessor;
2. add/replace/delete sets are mutually disjoint;
3. ZIP payload files are **exactly** `add + replace` plus the manifest;
4. every payload SHA-256 matches the manifest;
5. delete entries have no payload file;
6. listed migrations are changed PHP files below `database/migrations/`;
7. mutable generated config, key, installed state, secret, uploaded/private files, logs, backups and public runtime storage are never part of update file operations;
8. the external update-ZIP SHA-256 file matches the archive.

This makes an update package a deterministic transition artifact rather than a loose ZIP of recently edited files.

## Database/data rule

Packaging does not reset databases. Schema/data evolution is represented by migration files in the update manifest and is executed by the updater defined in roadmap 20.03. Site-local mutable data is outside normal add/replace/delete operations.

## Immutability

A published tag or GitHub Release is never overwritten. A correction increments `VERSION` and produces another full/update pair. Normal commits with an unchanged version may build CI artifacts but cannot mutate a release.
