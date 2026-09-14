# Contributing to Forwext

Forwext is an independent open-source forum platform developed by Warext Studios. Contributions are welcome only when they follow the project's architecture, usability, security and clean-room rules.

## Before contributing

Read these normative documents first:

- `docs/architecture/product-contract.md`
- `docs/architecture/scope-contract.md`
- `docs/architecture/usability-constitution.md`
- `docs/architecture/glossary.md`
- `docs/legal/clean-room-policy.md`

The binding roadmap and `PROJECT_STATUS.md` define the current implementation order. Do not bypass completed contracts by introducing an incompatible parallel architecture.

## Clean-room requirement

Other forum products may be studied for behavior, not copied for implementation.

Do not submit proprietary code, templates, phrases, schemas, migrations, styles, scripts, icons, screenshots-as-assets, leaked packages or AI-generated rewrites of proprietary material.

If your contribution was influenced by another forum product, describe only the behavioral reference in the pull request. The Forwext implementation must remain independently designed.

Third-party implementation code or assets may be included only after the project's dependency/license policy permits the license and the material is recorded in the required inventory/NOTICE process.

## Contribution provenance declaration

Every pull request must answer all of the following:

1. Is this contribution your original work or otherwise something you have the right to contribute?
2. Did you use another forum/community product as a behavioral reference? If yes, name it and confirm no proprietary implementation material was copied/adapted.
3. Did you incorporate third-party source code or assets? If yes, identify the source, version and license.
4. Did you use AI/code-generation tools? If yes, confirm prohibited proprietary material was not supplied as input.
5. Are any new dependencies introduced? If yes, identify them so license/security/maintenance review can be performed.

Unclear provenance blocks merge.

## Architecture and security

Contributions must use shared Forwext services instead of introducing parallel authentication, user, permission, moderation, notification, audit, attachment, analytics, queue/scheduler or settings systems.

Protected actions require backend authorization. UI hiding is not a permission check.

Security, privacy, migration/data-safety, audit, cPanel compatibility, mobile behavior and graceful degradation must be considered at the roadmap step where they apply.

## Completion standard

A route, empty class, placeholder page or TODO does not complete a roadmap item.

Relevant implementation, failure behavior, validation, permissions, migrations, tests and documentation must be included before a sub-step is marked complete.
