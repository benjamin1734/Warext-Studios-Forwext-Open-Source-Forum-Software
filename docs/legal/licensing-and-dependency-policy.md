# Forwext Licensing & Third-Party Dependency Policy

Status: **Normative for the Forwext 1.x development line**  
Roadmap step: **01.05 — Lisans ve third-party dependency politikası**

## 1. Forwext project license

Forwext source code is licensed under the **Apache License, Version 2.0** (`SPDX-License-Identifier: Apache-2.0`). The authoritative license text is the root `LICENSE` file.

Copyright notice for the initial project line:

`Copyright 2026 Warext Studios`

Contributions intentionally submitted for inclusion are accepted under the project license unless a separate written agreement explicitly applies.

## 2. Why Apache-2.0 was selected

Forwext intentionally supports a broad third-party add-on, integration, marketplace and hosting ecosystem. Apache-2.0 provides a permissive open-source distribution model, explicit copyright permissions and an explicit patent-license grant while allowing separately developed extensions and commercial integrations to choose their own compatible terms.

The project accepts the trade-off that downstream parties may keep their own modifications private when the Apache-2.0 terms permit it. Forwext does not add a custom “must publish hosted changes” clause; changing the standard license would create a different custom license and reduce ecosystem/legal predictability.

This is a product licensing decision for the repository, not a statement that every possible third-party component is automatically compatible.

## 3. Trademark boundary

The software license and project branding are separate concerns.

Apache-2.0 does not grant a general right to use Forwext or Warext Studios trademarks/logos to imply endorsement. A future trademark/brand-use policy may define permitted descriptive/community use without changing the software license.

## 4. Dependency inventory is mandatory

Every redistributed third-party component must be present in:

- `docs/standards/dependency-inventory.json` (machine-readable source of truth);
- `THIRD_PARTY_NOTICES.md` when attribution/license notice is required or useful for release review;
- release NOTICE/license aggregation generated later by the packaging system.

No dependency may be introduced only in a lockfile/vendor directory without inventory metadata.

The inventory records name, version, source, license, scope, redistribution state, required notices, security source, update status and approval metadata.

## 5. License acceptance policy

### Normally acceptable after ordinary review

Permissive licenses that are compatible with Apache-2.0 distribution are normally acceptable when their notice conditions are satisfied, including common cases such as:

- Apache-2.0;
- MIT;
- BSD-2-Clause;
- BSD-3-Clause;
- ISC;
- CC0-1.0 for material where that license is appropriate.

“Normally acceptable” is not automatic approval. Provenance, security and maintenance checks still apply.

### Conditional / architecture-and-license review required

The following require explicit review before inclusion:

- MPL-2.0 or other file-level copyleft;
- LGPL-2.1-or-later / LGPL-3.0-or-later libraries;
- GPL-3.0-or-later components;
- AGPL-3.0-or-later components;
- CC-BY licensed documentation/assets;
- dual/multi-licensed packages where the selected option must be recorded.

Strong-copyleft components must not silently change the distribution obligations of the Apache-2.0 Forwext core. If compatibility requires isolation, a subprocess/network integration or optional external service may be preferred, but the architecture cannot be distorted solely to evade license obligations.

### Rejected by default

Do not redistribute or vendor components with:

- no identifiable license;
- “all rights reserved” terms without explicit permission;
- non-commercial/no-derivatives restrictions incompatible with the intended distribution/use;
- leaked, pirated, nulled or provenance-unclear source/assets;
- source-available licenses that are incorrectly presented as open source;
- terms requiring undisclosed keys, telemetry, branding or commercial commitments that conflict with Forwext release requirements.

A separately operated external integration may have its own service terms, but it must not be mislabeled as a redistributable open-source dependency.

## 6. Security acceptance criteria

A dependency is not approved solely because its license is acceptable.

Before approval, verify as applicable:

- active/credible upstream ownership and release provenance;
- known security advisories/CVEs and whether supported versions are patched;
- no unresolved critical vulnerability on the version to be shipped unless a documented mitigation makes exposure impossible;
- signed/tagged/checksummed releases when upstream provides them;
- minimal transitive dependency surface;
- no unnecessary network/telemetry behavior;
- compatibility with PHP 8.4/8.5 or the relevant JS/build runtime;
- compatibility with cPanel release packaging when it is a runtime dependency.

Security-sensitive dependencies (auth, crypto, HTML sanitizer, HTTP client, archive/parser, image/media, payment, OAuth/WebAuthn) receive stricter review and must not be replaced by abandoned forks for convenience.

## 7. Maintenance and freshness criteria

For each dependency record:

- exact version/range policy;
- upstream release/advisory location;
- responsible Forwext subsystem;
- last review date;
- latest-known-compatible version at review time;
- update urgency classification.

Dependency updates should be evaluated continuously during development and before each release. Critical/security fixes may bypass normal feature cadence but still require tests and changelog/auditability.

Abandoned dependencies must be replaced, isolated or removed when their risk becomes unacceptable.

## 8. Version pinning and reproducibility

Development tooling may use lockfiles. Release builds must be reproducible enough to identify exactly which third-party versions are distributed.

Do not use unpinned mutable branches/tags as production release inputs.

Release artifacts later produced by Forwext must carry the same dependency/notice state that was reviewed for that version.

## 9. Vendoring policy

The cPanel release profile requires production dependencies to be present in release ZIPs even though Composer/npm are not required on the server.

That does **not** mean every dependency should be committed to Git history. Development/build dependency acquisition and release vendoring are separate concerns. The packaging step will construct release artifacts from locked, reviewed dependencies and include required notices.

If a dependency is committed/vendor-copied into the repository, its license/provenance must be recorded before merge.

## 10. Assets, fonts and content licenses

Code-license approval does not automatically approve fonts, icons, images, sample media or datasets.

Each redistributed asset must have explicit rights suitable for redistribution and modification where required by the product. Attribution requirements must be carried into NOTICE/credits. “Free download” without a clear license is not sufficient provenance.

## 11. Contribution policy

Pull requests that add dependencies must include:

- dependency name/version/source;
- selected SPDX license;
- reason the dependency is needed instead of existing/core capability;
- runtime/dev/build/asset scope;
- security/maintenance review notes;
- inventory and NOTICE changes.

A dependency PR is incomplete without this metadata.

## 12. Release compliance gate

A release is blocked when:

- the root `LICENSE` is missing or modified incorrectly;
- required NOTICE/attribution is missing;
- a redistributed component is absent from inventory;
- a component has unknown/incompatible terms;
- a known critical dependency vulnerability lacks an accepted mitigation;
- the release package contains a different dependency version than the reviewed inventory.

Later release tooling should automate these checks where practical, but automation never overrides license obligations.
