# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 06
LAST_COMPLETED_SUBSTEP = 07.02
CURRENT_STEP = 07.03
LAST_COMMIT = fdd38b4a72e42fa2e6fee467ed4dbeadcb920cb4
BLOCKERS = none
NEXT_STEP = 07.03 - Reactions/bookmarks/follow/ignore
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`; main step `07` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.02`.
- Current sub-step: `07.03 — Reactions/bookmarks/follow/ignore`
- Persistent server installation: browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state.
- Installation packaging: GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks.
- Permission system: shared global/node engine, starter profiles, analyzer, actor-bound gate, first-party namespace integration and mandatory security matrix completed.
- Forum/content foundation: node hierarchy, thread/post lifecycle, polls, discussion state, metadata, rich editor and audited moderation operations completed.
- Interaction foundation: private attachments plus mention autocomplete, cross-thread quotes, safe embeds, SSRF-hardened link previews and local emoji/smileys completed.
- Installer migration integrity: all current migrations are explicitly registered and regression-tested.
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0).

`LAST_COMMIT` records the implementation/fix commit that completed the last sub-step. Status-only commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must resolve and verify current `main` before changing files.

## Completed in 07.02

- Replaced exact-only mention UX with authenticated bounded prefix autocomplete while preserving stable user-id mention tokens and the previous exact-lookup API.
- Added source-forum-authorized cross-thread quoting with privacy-preserving unavailable responses for hidden/deleted/pending content.
- Added opaque base64url `plain64` quote payload rendering so quoted BBCode/HTML cannot break out of the quote container or become executable formatting.
- Preserved the iframe-free safe embed contract instead of introducing provider HTML/script execution.
- Added SSRF-resistant HTTPS link previews: public-host DNS validation, all-address fail-closed checks, private/reserved/IP-literal rejection, port 443 restriction, pinned-IP TLS transport, redirect revalidation, response/timeout bounds and curated text-only metadata.
- Added authenticated editor-header gating before outbound preview work and kept remote preview metadata out of `innerHTML`.
- Added first-party emoji/smiley catalog, `[emoji=...]` rendering and code-block literal behavior.
- Added native editor UI for debounced mention suggestions, post quoting, link-preview cards and emoji selection, including responsive styles.
- Added domain/database/HTTP/security regression tests covering mention wildcard handling, cross-thread authorization, quote breakout containment, mixed-DNS/rebinding/private-address SSRF cases and editor-header gating.
- Added architecture documentation; no migration was required because 07.02 introduces no persistent data.
- Feature commit: `bc6164cbd5cdc9a6bc1f14c7afcfd909096d8c55`; hardening fix: `fdd38b4a72e42fa2e6fee467ed4dbeadcb920cb4`.
- Final CI run `35064782254` passed PHP 8.4/8.5 PHPUnit, strict-types, Composer metadata, production dependency baseline, full/update package builds, artifact upload and GitHub Release.

## Completed in 07.01

- Added actor-bound private temporary attachment staging with `forum.view` + `forum.attachment.upload` authorization.
- Added server-side byte-signature/MIME inspection for JPEG, PNG, GIF, WebP, PDF, ZIP and bounded UTF-8 text instead of trusting client MIME.
- Added image decode/pixel limits and pure-PHP privacy stripping for JPEG EXIF/XMP/IPTC/comment metadata, PNG metadata chunks and WebP EXIF/XMP.
- Added optional GD thumbnail generation without making GD a minimum cPanel requirement.
- Added service preflight quotas plus database owner-row locking and in-transaction quota recheck so concurrent uploads cannot race past user limits.
- Added temporary-to-attached finalization with owner binding, active post/thread/forum validation, SHA-256/byte-length revalidation and collision-safe permanent storage paths.
- Added private authenticated download routes with persisted integrity checks, `nosniff`, private/no-store caching and correct generated-thumbnail extension handling.
- Added `AttachmentCleanupService`, hourly maintenance task definition and a bounded cleanup job handler that do not invent a privileged system user.
- Added migration `20260916001000_attachment_pipeline`, three attachment permissions, complete starter-profile rules and installer-registry coverage.
- Added domain/repository/service/migration/security tests and architecture documentation.
- Final CI run `35029822766` passed PHP 8.4/8.5 PHPUnit, strict-types, Composer metadata, production dependency baseline, full/update package builds, artifact upload and GitHub Release.

## Completed main step 06

- `06.01`: node/category/forum hierarchy.
- `06.02`: thread domain and lifecycle.
- `06.03`: post domain, history and moderation state.
- `06.04`: prefixes/tags/custom fields.
- `06.05`: poll system.
- `06.06`: drafts/read/watch state.
- `06.07`: rich editor, safe BBCode/rendering, preview, mentions/embeds and live metrics.
- `06.08`: audited thread/post moderation operations.

## Completed main step 05

- `05.01`: role/user-group model.
- `05.02`: shared global/node permission engine.
- `05.03`: permission starter profiles.
- `05.04`: permission analyzer/explanation UX.
- `05.05`: safe role appearance/banner system.
- `05.06`: first-party permission definitions/namespaces and runtime integration.
- `05.07`: mandatory permission-security matrix covering IDOR/BOLA, bypass, deny precedence, inheritance, numeric limits and UI/backend parity.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Every releasable development version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full ZIP is suitable for a clean installation. The update ZIP upgrades the previous supported installation and must not reset the database. Update manifests carry source version, target version, add/replace/delete sets, migrations, rebuild actions and checksums. Normal updates preserve site-specific config/uploads/storage data and advance existing data through migrations.

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
