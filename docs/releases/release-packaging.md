# Forwext Release Packaging Standard

This document defines the permanent two-package release rule and is subordinate to the binding v2 roadmap plus `docs/releases/versioning-and-continuation-protocol.md`.

## Required release artifacts

Every releasable version produces:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full package is the complete current production application for a clean installation.

The update package upgrades the immediately previous supported release to the target release while preserving the existing database and site data unless a wider source range is explicitly published and tested.

Example:

```text
Forwext v0.4.8
├── forwext-v0.4.8-full.zip
└── forwext-v0.4.8-update.zip
```

## Full package requirements

The full ZIP contains everything needed for browser-based installation:

- Forwext application/core/module source;
- production PHP dependencies;
- built frontend assets;
- installer/updater code;
- all migrations required to construct the target schema;
- default resources/theme/languages;
- required license/NOTICE/third-party attribution files;
- web-server configuration templates appropriate to distribution.

It must not require Composer, npm, Node.js, SSH, Redis or persistent workers on the supported minimum cPanel production profile.

## Update package requirements

An update package contains:

- files added by the target release;
- files changed by the target release;
- an update manifest declaring obsolete application files that must be deleted;
- migrations/data migrations for the version transition;
- exact source/target version metadata;
- rebuild/cache/template/index instructions where necessary;
- dependency/module compatibility metadata;
- health/integrity actions.

An update does not reinstall or reset the database.

## Update manifest

The production updater uses a machine-readable manifest with at least:

```text
source version
target version
files to add
files to replace
files to delete
protected mutable paths
migrations to execute
module/add-on compatibility requirements
required server capabilities
post-update rebuild actions
health/integrity verification actions
checksums/signature metadata
```

An update built for the wrong source version must stop before applying file deletion, migrations or other destructive changes.

## Protected site data

Ordinary updates do not delete or overwrite site-specific mutable data such as:

- generated local configuration/secrets;
- user uploads;
- private attachments/media;
- logs;
- backups;
- runtime site-specific storage.

Exact protected paths are finalized when the runtime/storage layout is implemented.

## Database policy

After the first persistent installation milestone at roadmap step **03.03 — Migration / Install / Upgrade Engine**, routine development updates use migrations rather than database resets.

A migration may:

- create tables/indexes/columns;
- transform existing data;
- backfill values;
- safely rename/replace structures through an explicit migration path.

Destructive migrations require explicit safeguards and an appropriate backup/recovery strategy. Silent data deletion is not an acceptable shortcut for development convenience.

## Update sequence target

The production updater follows a contract equivalent to:

```text
validate current/source/target version
validate server/module/add-on compatibility
acquire update lock / maintenance mode
backup or verify required safety checkpoint
stage and verify files/checksums
apply file operations
run versioned migrations
run rebuild/cache/template/index actions
execute health/integrity checks
write installed version atomically
release update lock
```

Failed update recovery/rollback is a production requirement of the later migration/updater/release implementation steps.

## Development-period delivery rule

Before automated packaging tooling exists, development commits do not need meaningless placeholder ZIP artifacts. Once packaging becomes operational, every version actually delivered for installation/update testing follows this standard.

The first persistent server installation occurs after step **03.03** is complete enough for a verified full install. From that point forward, normal testing advances the same database through update packages/migrations instead of repeated clean database installation.
