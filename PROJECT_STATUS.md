# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.5-dev
LAST_COMPLETED_MAIN_STEP = 03
LAST_COMPLETED_SUBSTEP = 04.07
CURRENT_STEP = 04.08
LAST_COMMIT = git:HEAD
BLOCKERS = none
NEXT_STEP = 04.08
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`
- Current main step: `04`
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.07`, `04.01` through `04.07`
- Current sub-step: `04.08 — Custom profile URL system`
- Persistent server installation: `available — browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state`
- Installation packaging: `GitHub CI builds vendor-inclusive cPanel install/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks`
- OAuth/connected accounts: `Google + Discord provider abstraction, PKCE/state, verified-email linking, duplicate prevention, unlink safety and encrypted secret references completed`
- Profile/media: `persisted avatar + banner + about + social links + tab preferences, owner-safe visibility policy, private media storage and native PHP member/profile/media routes completed`
- Profile web privacy: `viewer identity derives only from validated authentication sessions; hidden profiles/media fail closed as 404 and private media is never exposed through a direct public-storage URL`
- Profile music: `permission bridge, private uploaded audio, exact-host HTTPS external policy, native mobile-safe player, volume/mute/autoplay/loop preferences, byte-range delivery, CSP media allowlist and moderation audit completed`
- Profile music permission bridge: `05.x shared role/group engine can replace ProfileMusicPermissionResolver without rewriting profile music domain or HTTP handlers; baseline external/moderation capabilities remain disabled by default`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT = git:HEAD` intentionally means the current commit on `main`; a Git commit cannot contain its own final SHA without changing that SHA. Each continuation session must resolve and verify `main` HEAD before changing files.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Every releasable development version provides both:

- `forwext-X.Y.Z-install.zip`
- `forwext-X.Y.Z-update.zip`

Update ZIPs contain only new/changed packaged application files. They do not include helper/delete/readme manifests. If a previously packaged file must be removed, that deletion is communicated explicitly with the release/update instructions. Normal updates preserve the existing database through migrations and must not blindly overwrite site-specific config/uploads/storage data.

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
