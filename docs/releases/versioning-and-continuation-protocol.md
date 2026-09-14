# Forwext Versioning, Delivery & Continuation Protocol

Status: **Normative for the Forwext 1.x development line**  
Roadmap step: **01.06 — Sürüm, teslim ve devam protokolü**

This document fixes how Forwext versions, changelog entries, progress state, migrations, release packages and new-session continuation work.

## 1. Versioning model

Forwext follows **Semantic Versioning 2.0.0** for releasable versions.

Version format:

`MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD]`

Examples:

- `0.1.0-alpha.1`
- `0.8.0-beta.2`
- `1.0.0-rc.1`
- `1.0.0`
- `1.0.1`
- `1.1.0`

The current `0.0.0-dev` value is a development-line marker, not a public production release.

### Increment rules

- **PATCH**: backward-compatible fixes/security/maintenance changes that do not add incompatible public API behavior.
- **MINOR**: backward-compatible feature additions or meaningful platform capability growth.
- **MAJOR**: incompatible public API/extension/upgrade contract changes after 1.0.

Before 1.0, breaking architecture work may occur while the platform is still under active construction, but once a version has been released for real installations its database/update path must still be handled by migrations rather than reset-by-convenience.

Prerelease identifiers use `alpha`, `beta` and `rc` in that order. A stable version never depends on a prerelease-only migration path that cannot upgrade cleanly.

## 2. Version source of truth

The repository root `VERSION` file is the human/tool-readable project version marker.

When release tooling is implemented, the following must agree before a release is valid:

- root `VERSION`;
- release manifest target version;
- full/update archive names;
- installed-version metadata;
- generated checksums/release metadata;
- changelog release heading.

A mismatch blocks packaging.

## 3. Changelog protocol

`CHANGELOG.md` records notable project changes.

During active roadmap development, completed sub-steps are recorded under `Unreleased` with their roadmap IDs. Once a releasable version is cut, the relevant entries are grouped under a version/date heading and `Unreleased` continues above it.

Changelog entries describe user/admin/developer-visible behavior and important architecture/security/release changes. Internal formatting-only edits do not require noise entries unless they affect delivery or compatibility.

Security-sensitive fixes may use limited detail before coordinated disclosure, but the release record must still identify that a security update occurred.

## 4. Roadmap progress protocol

`PROJECT_STATUS.md` is the canonical human-readable continuation pointer.

It must contain at least:

```text
PROJECT
PLAN_VERSION
CURRENT_VERSION
LAST_COMPLETED_MAIN_STEP
LAST_COMPLETED_SUBSTEP
CURRENT_STEP
LAST_COMMIT
BLOCKERS
NEXT_STEP
```

`LAST_COMMIT = git:HEAD` means the reader must resolve the current `main` HEAD. A commit cannot contain its own final SHA without changing itself, so the branch HEAD is the canonical concrete SHA.

A sub-step is marked complete only after its required implementation/documentation and applicable validation are complete. A skeleton or critical TODO does not advance the pointer.

When the final sub-step of a main step is completed, `LAST_COMPLETED_MAIN_STEP` advances and `CURRENT_STEP/NEXT_STEP` point to the first sub-step of the next main step.

## 5. New-session continuation protocol

A new development session must perform this sequence before editing:

1. Read the binding master plan (`forwext_master_gelistirme_plani_v2.txt` or its future explicitly superseding version).
2. Read `PROJECT_STATUS.md`.
3. Resolve GitHub `main` HEAD and inspect recent commits/files.
4. Verify that `LAST_COMPLETED_SUBSTEP` is actually represented in the repository.
5. Prefer the newest binding plan/user instruction if an older document conflicts.
6. Continue from `NEXT_STEP`; do not rewrite completed work without a concrete defect or contract mismatch.
7. Complete implementation and applicable tests/checks.
8. Add/update migrations, docs and policy files when the step requires them.
9. Commit the completed logical sub-step to `main` using the sub-step number in the message.
10. Update `PROJECT_STATUS.md` and `CHANGELOG.md` in the same logical delivery.

Recommended commit form:

`type(XX.XX): concise description`

Example:

`docs(01.06): define version and delivery protocol`

## 6. Full + Update ZIP rule

Every **releasable** version, once packaging tooling is operational, produces both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full package installs the target version from zero.

The update package moves the immediately previous supported Forwext release to the target release while preserving site data and running migrations.

Development commits before packaging is operational are not fake “releases” and do not need empty/useless ZIP artifacts. The two-ZIP rule becomes mandatory for every version actually delivered for installation/update testing after the packaging engine exists.

## 7. Update source-version contract

An update package contains explicit `source_version` and `target_version` metadata.

Default rule: an update ZIP accepts only the immediately previous supported version unless a release explicitly publishes and tests a wider source range.

A wrong source version must fail **before** file deletion, migration or other destructive changes.

Skipping versions is not assumed safe unless an upgrade path is explicitly supported and tested.

## 8. Migration rule

Schema/data changes are represented by versioned migrations once the migration/install/upgrade engine exists.

After the first persistent hosting installation milestone at **03.03**, routine development must not reset the database to apply normal changes.

A release that changes persistent schema/data without the required migration is incomplete.

Update packages must run the exact migrations required for their source→target transition, record migration history and stop/recover safely on failure according to the migration engine contract.

Manual SQL instructions are not the normal production update mechanism.

## 9. Site-data preservation rule

Normal updates do not blindly overwrite/delete site-specific mutable data, including generated config/secrets, uploads, private media, runtime storage, logs and backups.

The update manifest must explicitly distinguish application-managed immutable files from protected mutable paths.

Removed application files are deleted only through a reviewed update manifest.

## 10. Rebuild and health rule

After file/migration application, the updater performs the rebuilds required by the target version, such as caches, compiled templates, search/index metadata or module registries.

A release update is not considered successful until required health/integrity checks pass and installed-version state is written safely.

## 11. Release compliance gate

A releasable package is blocked when applicable checks fail, including:

- required automated tests;
- migration/install/update verification;
- source/target version consistency;
- dependency inventory/license/NOTICE compliance;
- package checksum/integrity generation;
- unsupported server capability checks;
- critical security failures;
- missing release notes/changelog.

Exact executable gates are implemented in later runtime/packaging roadmap steps; this document defines the contract they must enforce.

## 12. Release artifacts and Git history

Source commits and release archives serve different purposes.

GitHub `main` is the source-of-truth development line. Release ZIPs are generated artifacts tied to an immutable version/tag/commit. They must not be hand-edited after generation.

When tagging/release automation exists, a release record must identify the exact commit used to build its artifacts.

## 13. Acceptance status for roadmap step 01.06

At this stage no installer/migration runtime exists yet, so 01.06 does not fabricate executable release ZIPs. Completion requires the normative version/delivery/continuation contract, the existing packaging standard aligned to v2, the machine-readable release policy, root version marker, changelog and progress pointer.

Later implementation steps must enforce this contract with real installers, migrations, packaging manifests, tests and health checks.
