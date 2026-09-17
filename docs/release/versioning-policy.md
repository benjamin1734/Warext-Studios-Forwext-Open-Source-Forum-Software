# Forwext release and versioning policy

Forwext development releases use immutable GitHub tags and immutable release assets. Once a release is published, its ZIP files are never replaced in place. Any correction or follow-up must advance `VERSION` and publish a new tag.

## Version forms

Main development milestones use `X.Y.Z-dev`, for example `0.0.7-dev`. A main milestone represents the completed main-step line for that version.

Side updates and hotfixes use `X.Y.Z.NN-dev`, for example `0.0.7.01-dev`, `0.0.7.02-dev`, and so on. `NN` starts at `01` and advances within the same main line.

When the next main milestone is completed, the version advances to the next main version, for example from the latest `0.0.7.NN-dev` to `0.0.8-dev`.

## Tags and packages

Each version has its own prerelease tag and its own assets:

- Main: `v0.0.7-dev`
  - `forwext-0.0.7-dev-install.zip`
  - `forwext-0.0.7-dev-update.zip` when a predecessor exists
- Side update: `v0.0.7.01-dev`
  - `forwext-0.0.7.01-dev-install.zip`
  - `forwext-0.0.7.01-dev-update.zip`

SHA-256 files are published beside each ZIP.

## Differential update chain

The update ZIP always compares the new full package with the immediately preceding published Forwext release. Examples:

- `0.0.7.01-dev` updates from `0.0.7-dev`.
- `0.0.7.02-dev` updates from `0.0.7.01-dev`.
- `0.0.8-dev` updates from the latest published `0.0.7.NN-dev`.

The update ZIP contains only added or changed packaged files. Removed packaged files are reported by the build but are not represented by artificial helper files inside the ZIP.

## Publication rules

The release workflow publishes a GitHub Release only when the `VERSION` file changes, or when an explicit manual release is requested for a version that does not already exist. Normal commits with an unchanged `VERSION` still run tests and can build CI artifacts, but they do not modify a published Release.

Before publication the workflow verifies that neither the Git tag nor the GitHub Release already exists and that the new version advances beyond the latest valid Forwext development tag. `--clobber` is forbidden for published release assets.

## Transition at 0.0.7

Before this policy was introduced, the old workflow refreshed assets under the existing `v0.0.7-dev` prerelease. That historical behavior ends with the adoption of this policy. The current `v0.0.7-dev` asset set is treated as the frozen transition baseline, and `v0.0.7.01-dev` is the first immutable side-update release.

Anyone holding an older locally downloaded `0.0.7-dev` package from before that final transition baseline should use the full `0.0.7.01-dev` installation package once instead of assuming the `0.0.7.01-dev` differential ZIP contains every change from an earlier overwritten `0.0.7-dev` copy. From `0.0.7.01-dev` onward, the differential chain is immutable and deterministic.
