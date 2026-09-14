# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.0-dev
LAST_COMPLETED_MAIN_STEP = 02
LAST_COMPLETED_SUBSTEP = 03.05
CURRENT_STEP = 03.06
LAST_COMMIT = git:HEAD
BLOCKERS = none
NEXT_STEP = 03.06
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`
- Current main step: `03`
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.05`
- Current sub-step: `03.06`
- Persistent server installation required yet: `available — migration engine and first concrete core infrastructure migration exist; a full application install still requires later application-schema migrations`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT = git:HEAD` intentionally means the current commit on `main`; a Git commit cannot contain its own final SHA without changing that SHA. Each continuation session must resolve and verify `main` HEAD before changing files.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Once release packaging is operational, every releasable version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

Normal update packages preserve the existing database through migrations and must not blindly overwrite site-specific config/uploads/storage data.

## Continuation protocol

A new work session must:

1. Read the binding master roadmap.
2. Read this status file.
3. Inspect current GitHub `main` and recent commits.
4. Continue from `NEXT_STEP` without rewriting completed work unnecessarily.
5. Apply the real requirements of the current sub-step.
6. Run applicable tests/acceptance checks.
7. Add migrations when the change requires them.
8. Commit to GitHub and update this status pointer.
