# Forwext release and versioning policy

Forwext releases use immutable GitHub tags and immutable release assets. Development builds remain prereleases; stable semantic versions such as `1.0.0` are production releases. Once a release is published, its ZIP files are never replaced in place. Any correction or follow-up must advance `VERSION` and publish a new tag.

## Version forms

Production releases use normal semantic versions such as `1.0.0` and are published as stable GitHub Releases.

Main development milestones use `X.Y.Z-dev`, for example `0.0.7-dev`. Side updates and hotfixes use `X.Y.Z.NN-dev`, for example `0.0.7.01-dev` and `0.0.7.02-dev`; `NN` starts at `01`.

A production release must compare newer than every recognized Forwext tag. A development tag for a future core version may follow a stable release, but a prerelease for the same semantic core sorts before that core's production tag.

## Tags and package contract

Each version has its own immutable tag. Development versions are marked prerelease; production versions are not. The package naming contract is fixed:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

Development/prerelease versions use their complete `VERSION` token in the same contract. For example `0.0.7.03-dev` produces:

- `forwext-v0.0.7.03-dev-full.zip`
- `forwext-v0.0.7.03-dev-update.zip`

SHA-256 files are published beside each ZIP. `full.zip` is the complete clean-install package. `update.zip` upgrades the immediately preceding supported published version and never resets the existing database.

## Differential update chain

The update ZIP compares the new full package with the immediately preceding published Forwext release. Examples:

- `0.0.7.01-dev` updates from `0.0.7-dev`.
- `0.0.7.02-dev` updates from `0.0.7.01-dev`.
- `0.0.8-dev` updates from the latest published `0.0.7.NN-dev`.

Every generated update ZIP contains `update-manifest.json`. The manifest carries, at minimum:

- `source_version`
- `target_version`
- `add`
- `replace`
- `delete`
- `migrations`
- `rebuild`
- SHA-256 payload `checksum` data

Site-specific configuration, uploads and mutable storage are not intentionally replaced by ordinary updates. Database changes move forward through versioned migrations; normal update packaging never resets forum/user data.

The update ZIP contains real added/replaced payload files plus the manifest. Deleted packaged files are represented by the manifest's `delete` list instead of artificial tombstone files. Migration files added or replaced by the package are listed in `migrations`. Rebuild actions are an explicit list and may be empty when no rebuild is required.

## Legacy release compatibility

Published releases are immutable, so historical prereleases that already used the older `forwext-*-install.zip` asset name are not renamed in place. The release workflow may read that legacy asset only as the source package for the next differential update. All newly generated assets use the binding `forwext-v...-full.zip` / `forwext-v...-update.zip` contract.

## Publication rules

The release workflow publishes a GitHub Release only when the `VERSION` file changes, or when an explicit manual release is requested for a version that does not already exist. Normal commits with an unchanged `VERSION` still run tests and build a full CI artifact, but do not modify a published Release.

Before publication the workflow verifies that neither the Git tag nor the GitHub Release already exists and that the new version advances beyond the latest recognized Forwext development or production tag. Published assets are never overwritten.

## Release verification and upgrade rehearsal

A merge commit changes the published application only when its first-parent diff includes `VERSION`; a normal merge that does not change the version does not publish a release. An explicit manual dispatch remains available for an unpublished version.

Before creating a tag or uploading immutable assets, the package workflow requires completed **successful** independent push runs at the exact release commit for the security, database migration, performance/observability and qualification-matrix workflows. Missing, failed, canceled or timed-out qualification blocks publication. Passing only the package build is insufficient.

When a predecessor release exists, the package workflow builds the differential ZIP, then rehearses the add/replace/delete operations against a copy of the previous published full package. The resulting application files must have exactly the same SHA-256 inventory as the new full package. The rehearsal seeds existing installation configuration, secrets, uploads, logs and backups and asserts they remain byte-for-byte unchanged. CI additionally exercises successful, corrupted-payload, incomplete-update and protected-file regression scenarios.

## Transition at 0.0.7

The historical `v0.0.7-dev` transition baseline and already published side-update assets remain immutable. Compatibility with their older installation-package name exists only for reading the predecessor during differential generation; it does not change the current package contract.

## Production publication

A stable `X.Y.Z` VERSION is classified as a production release. It runs the same PHP 8.4/8.5, SDK, React UI, Next.js and package-integrity gates as development builds, resolves the immediate predecessor across recognized development/production tags, builds the full ZIP plus differential update ZIP when a predecessor exists, publishes SHA-256 files, and creates a GitHub Release without the prerelease flag.

Forwext `1.0.0` is the first production release governed by this rule.
