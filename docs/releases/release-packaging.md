# Forwext Release Packaging Standard

This document defines the permanent two-package release rule.

## Required release artifacts

Every releasable version produces:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full package is the complete current production application for a clean installation.

The update package is intended to upgrade the immediately previous supported release to the target release while preserving the existing database and site data.

Example:

```text
Forwext v0.4.8
├── forwext-v0.4.8-full.zip
└── forwext-v0.4.8-update.zip
```

## Full package requirements

The full ZIP contains everything needed for browser-based installation:

- Forwext application/core/module source
- production PHP dependencies
- built frontend assets
- installer/updater code
- all migrations required to construct the target schema
- default resources/theme/languages
- web-server configuration templates appropriate to distribution

It must not require running Composer or npm on the production server.

## Update package requirements

An update package contains:

- files added by the target release
- files changed by the target release
- an update manifest declaring files that must be deleted
- migrations/data migrations for the version transition
- target-version metadata
- rebuild/cache/index instructions where necessary

An update does not reinstall or reset the database.

## Update manifest

The final updater will use a machine-readable manifest with at least:

```text
source version
minimum accepted source version
target version
files to add
files to replace
files to delete
migrations to execute
module compatibility requirements
required server capabilities
post-update rebuild actions
health verification actions
checksums/signature metadata
```

An update built for the wrong source version must stop before applying destructive changes.

## Protected site data

Ordinary updates do not delete or overwrite site-specific mutable data such as:

- generated local configuration/secrets
- user uploads
- private attachments/media
- logs
- backups
- runtime site-specific storage

Exact protected paths are finalized when the runtime/storage layout is implemented.

## Database policy

After persistent installation begins at roadmap step 03.02, routine development updates use migrations rather than database resets.

A migration may:

- create tables/indexes/columns
- transform existing data
- backfill values
- safely rename/replace structures through an explicit migration path

Destructive migrations require explicit safeguards and an appropriate backup/recovery strategy. Silent data deletion is not an acceptable shortcut for development convenience.

## Update sequence target

The production updater is expected to follow an order similar to:

```text
validate current version
validate server/module compatibility
acquire update lock / maintenance mode
backup or verify required safety checkpoint
stage and verify files
apply file operations
run versioned migrations
run rebuild/cache/index actions
execute health checks
write installed version
release update lock
```

Failed update handling and rollback/recovery are production requirements of roadmap phase 18.

## Development-period delivery rule

Before the final automated packaging tooling exists, development commits may not yet have meaningful ZIP artifacts. Once packaging becomes operational, every version delivered for server testing follows this standard.

The first persistent server installation should be performed after step 03.02. From that point forward, normal testing advances through update packages/migrations instead of repeated clean database installation.
