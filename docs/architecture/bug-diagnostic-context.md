# Bug Report Automatic Diagnostic Context

Roadmap sub-step **11.02** adds privacy-safe automatic technical context capture for bug reports. The collector is designed to be consumed by the page-level bug-report form in 11.03 without trusting hidden browser fields for authoritative context.

## Captured context

Each submitted bug report may have one diagnostic record containing:

- query-free request URL path;
- matched route name;
- authenticated reporter user id when available;
- canonical forum/thread/post ids when present in route parameters;
- active theme key;
- module key;
- browser family and major version;
- operating-system family;
- device class;
- HMAC user-agent fingerprint;
- request id;
- UTC capture timestamp.

The diagnostic row is keyed one-to-one by bug-report id.

## URL privacy

The request URI is reduced to its origin-form path before persistence.

For example:

`/threads/abc?token=secret&email=user@example.com`

is persisted only as:

`/threads/abc`

The query string is never stored by the diagnostic collector. This prevents CSRF values, reset tokens, e-mail addresses, search strings, OAuth codes, and other query data from becoming bug-report metadata.

The collector does not persist request bodies or cookies.

## User context

The reporter user id is stored as a normal internal entity reference and uses `ON DELETE SET NULL`. Account deletion therefore does not erase the bug report or its technical context.

No e-mail, profile field, session id, auth token, or other user secret is copied into diagnostics.

## Forum/thread/post context

The collector reads canonical router parameters rather than user-submitted diagnostic fields.

Supported route parameter names are:

- forum: `forumId`, `forumNodeId`, `nodeId`;
- thread: `threadId`;
- post: `postId`.

Only canonical 32-character lowercase hexadecimal entity ids are accepted. Invalid/noncanonical route values are ignored rather than copied.

These ids are intentionally stored without foreign keys so a deleted forum/thread/post does not erase historical diagnostic evidence.

## Theme and module

The collector supports runtime request attributes:

- `theme_key`;
- `module_key`.

A valid explicit theme attribute is stored. Until the later theme runtime supplies one, the native first-party UI records the real current fallback as `default`.

If no explicit module attribute exists, the module is derived from the first segment of the matched route name. For example `support.ticket.detail` becomes `support`.

No arbitrary client-submitted theme/module value is trusted.

## Browser and device privacy

Raw User-Agent text is never persisted.

`BugBrowserDeviceClassifier` stores only:

- browser family such as chrome/firefox/safari/edge/opera;
- major browser version;
- OS family;
- device class: desktop/mobile/tablet/bot/unknown;
- a HMAC SHA-256 fingerprint produced through the existing `AuthenticationFingerprint` secret.

The fingerprint lets later diagnostics compare repeated client contexts without storing the identifying header itself.

User-Agent input is bounded to 2048 bytes before classification/fingerprinting so oversized headers cannot block bug reporting.

No client IP is captured by 11.02 because the binding roadmap does not require IP and storing it would add unnecessary privacy exposure.

## Request id

When `RequestIdMiddleware` has attached a valid request id, the diagnostic record stores it. This enables correlation with existing application logs/audit streams without copying those logs into the bug report.

Malformed or unavailable request ids are stored as NULL.

## Atomic submission

`BugReportSubmissionService` composes:

1. the permission-aware 11.01 `BugReportService`;
2. `BugDiagnosticContextCollector`;
3. `BugDiagnosticContextRepository`.

Bug report creation, workflow creation-history entry, and diagnostic context persistence occur in one database transaction. If context persistence fails, the submission transaction is not considered complete.

The service also participates in an already-open transaction instead of forcing nested transaction behavior.

## Persistence

Migration `20260918022000_bug_diagnostic_context` adds:

`forwext_bug_report_diagnostics`

with indexes for route, module, forum/thread/post context, request id, and privacy-safe client fingerprint.

The only foreign keys are:

- bug report → cascade on report deletion;
- actor user → set NULL on account deletion.

## 11.03 integration

The 11.03 bug-report form now consumes this collector through `BugReportSubmissionService` rather than accepting client-supplied diagnostic fields as authoritative context.

The page-accessible form adds reproduction steps, expected/actual results, an optional query-free source-page path hint and hardened private screenshot/file attachments. The source-page hint is informational; matched route, actor, route entities, theme/module, client summary and request id continue to come from the server-side 11.02 collector.

Reporter-facing history/detail/additional-information and notification tracking remain 11.04.

Duplicate detection, staff dashboard, filters, analytics, export, search, and centralized bug audit remain 11.05.
