# ACP UX Quality Standard (17.06)

Forwext native Administration surfaces use one progressive-disclosure quality contract without creating a second authorization layer.

## Contract

Every complex ACP surface should provide the controls that fit its domain:

- bounded server-side search and/or filtering,
- task-language explanations instead of unexplained implementation jargon,
- explicit safe defaults and effective-value precedence,
- a read-only preview or verification path where the domain can provide one safely,
- confirmation proportional to destructive impact,
- reset, override removal, retained-data mode, rollback or an explicit recovery path when supported by the underlying domain,
- responsive controls that remain usable on narrow screens.

The UI is never authority. Existing backend permission checks, CSRF middleware, audit services, typed validation and domain invariants remain mandatory.

Static ACP presentation is centralized in `public/assets/admin.css`; native administration renderers do not embed per-page `<style>` blocks. Shared focus states and coarse-pointer touch targets therefore have one maintained source while authorization and mutation behavior remain server-side.

## 17.06 coverage

- Administration dashboard: permission-filtered search, action-needed summaries and shared safe-work guidance.
- Users / Access / Forums / Content / Moderation: shared guidance; Access and Forums gain bounded presentation filters; Permission Analyzer remains the effective-access preview; saved role appearance gets a non-mutating preview.
- First-party Module Manager: server-validated text + lifecycle-state filtering, effective scoped-value explanation, dependency/conflict verification and existing override/uninstall recovery controls.
- System / Integrations: existing text/section filters, generated-override reset, secret confirmation and runtime capability verification are brought under the shared guidance contract.
- System Operations: bounded section filtering plus existing permission-gated health/integrity, backup verification, redacted logs, typed destructive confirmations and environment-override fail-closed behavior.

Representative ACP browser acceptance now runs in Chromium at 390px mobile and 1440px desktop. The gate verifies the Administration stylesheet is loaded and applies the common horizontal-overflow, keyboard-navigation, failed-request/console and 44px coarse-pointer control checks used by the public shell.

No database migration is required. Filters and guidance are request-scoped presentation state only. The minimum cPanel runtime gains no Composer/npm/Node/Redis/Docker/Supervisor requirement.
