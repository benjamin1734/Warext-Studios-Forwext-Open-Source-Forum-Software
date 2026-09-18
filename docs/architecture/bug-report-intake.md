# Bug Report Form and Attachment Intake

Roadmap sub-step **11.03** adds the authenticated user-facing bug-report intake surface on top of the 11.01 workflow and 11.02 privacy-safe diagnostic collector.

## UX surface

Authenticated native PHP pages render a compact bug icon that links to `/bugs/new`. The entry contains no visible text; it uses an accessible label/title.

A small first-party script appends only the current `window.location.pathname` as `source_path`. Query strings and fragments are deliberately excluded before the request leaves the page.

The bug form itself includes:

- category;
- title;
- short summary;
- reproduction steps;
- expected result;
- actual result;
- screenshot/file uploads.

Uploads are part of the same multipart form. There is no separate upload page.

## Source-page context

`source_path` is a user-context hint, not an authorization or diagnostic authority. It is normalized to a query-free, fragment-free same-origin-style path and is stored in the intake details row.

The authoritative automatic context remains the 11.02 `BugDiagnosticContextCollector`, which derives matched route, actor, route entities, theme/module, browser/device summary and request id from the server-side request.

## Attachment security

Bug attachments reuse the hardened first-party attachment pipeline:

1. verified PHP HTTP upload reader;
2. per-file byte limit;
3. signature/MIME detection;
4. image decode/dimension checks;
5. EXIF/private metadata stripping for supported images;
6. SHA-256 integrity metadata;
7. private storage only.

Allowed formats follow the existing attachment inspector: JPEG, PNG, GIF, WebP, PDF, ZIP and safe UTF-8 plain text.

The default bug intake policy accepts at most five files and uses the global attachment per-file limit (25 MiB by default).

## Atomic persistence

`BugReportFormSubmissionService` composes the existing 11.02 `BugReportSubmissionService`.

Within one database transaction it persists:

1. bug report;
2. public creation-history event;
3. automatic diagnostic context;
4. reproduction/expected/actual/source details;
5. attachment metadata.

Attachment bytes are written to private storage under:

`bugs/reports/{reportId}/{attachmentId}/{sha256}.{extension}`

If the submission fails after a storage write, successfully written objects are deleted as compensation.

## Download authorization

The attachment download service first calls the permission-aware `BugReportService::report()` method.

Therefore:

- the reporter needs `bug.report.view_own`;
- other users cannot fetch the file by guessing ids;
- staff access requires the existing `bug.report.view_all` backend permission.

The bytes are rechecked against persisted byte size and SHA-256 before delivery.

## Persistence

Migration `20260918023000_bug_report_intake` adds:

- `forwext_bug_report_details`;
- `forwext_bug_report_attachments`.

Report foreign keys cascade on report deletion. Attachment owner references use `ON DELETE SET NULL`.

## Roadmap boundary

11.03 does not implement the reporter list/detail/history/additional-information surface. That remains 11.04.

Duplicate detection, staff dashboard, assignment UX, analytics, export, search and centralized bug audit remain 11.05.
