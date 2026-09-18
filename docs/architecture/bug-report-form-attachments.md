# Bug Report Form and Attachments

Roadmap sub-step **11.03** adds the first-party bug-report submission UX on top of the 11.01 workflow and 11.02 privacy-safe diagnostic collector.

## Global access

Authenticated native pages rendered through the shared `ProfileHtml` shell expose a fixed **Hata bildir** action.

The link is enhanced by the first-party `bug-report-link.js` asset. It adds only `window.location.pathname` as the optional source path. Query strings and fragments are deliberately excluded so reset tokens, search terms, OAuth codes and similar values are not propagated into the form URL.

The server-provided link remains usable if JavaScript is disabled.

## Native route

`GET|POST /bugs/report`

uses:

- authenticated viewer resolution;
- the shared permission engine through `bug.report.create`;
- a dedicated bug-report CSRF scope;
- the existing 11.01 bug category workflow;
- the 11.02 diagnostic collector;
- the shared hardened attachment inspector and private storage driver.

The form collects:

- category;
- title;
- short summary;
- reproduction steps;
- expected result;
- actual result;
- optional screenshot/file attachments.

## Source page versus diagnostic context

`reported_source_path` is a user/browser supplied convenience field. It is sanitized to a path-only value and is **not** treated as authoritative technical context.

The 11.02 `BugDiagnosticContextCollector` remains the authoritative automatic diagnostic source. It continues to collect route/request/runtime information without trusting hidden form fields.

This separation prevents a manipulated form field from forging server-derived forum/thread/post/theme/module context.

## Attachments

Bug report attachments reuse the same hardened primitives used by the forum/support upload pipeline:

- verified PHP HTTP-upload source reader;
- per-file size limit;
- signature-based media detection;
- image decode/dimension validation;
- image metadata sanitation;
- normalized client filenames;
- private storage only.

Allowed formats remain the shared attachment policy: JPEG, PNG, GIF, WebP, PDF, ZIP and safe UTF-8 text.

Default 11.03 limit: maximum **5 files per bug report**, each within the shared 25 MiB per-file policy.

Stored paths are report-scoped:

`bugs/reports/{reportId}/{attachmentId}/{sha256}.{extension}`

If a database operation fails after a private object has been written, the submission service compensates by deleting objects already written during that submission.

## Persistence

Migration `20260918023000_bug_report_form_intake` adds:

- `forwext_bug_report_intake`
- `forwext_bug_report_attachments`

The intake table stores reproduction steps, expected/actual results, optional path-only reported source and creation time.

Attachment metadata stores content type, extension, size, SHA-256, storage path, optional dimensions and metadata-stripping state. Account deletion sets attachment owner to NULL without deleting the bug report.

## Atomicity

`BugReportFormSubmissionService` composes 11.01 and 11.02 rather than reimplementing them.

Within one database transaction it creates:

1. bug report;
2. workflow creation-history entry;
3. automatic diagnostic context;
4. form intake;
5. attachment metadata.

Private object storage is external to the SQL transaction, so written objects are tracked and removed if the transaction throws.

## Security boundaries

- CSRF is enforced before POST handling.
- Permission checks are backend-enforced by `BugReportService`.
- Form output is escaped.
- Source-path query/fragment data is dropped.
- User-supplied source path never overrides server diagnostic route/entity context.
- Files are never trusted by filename or browser MIME declaration.
- Storage paths are generated server-side from canonical ids and SHA-256 values.
- Upload metadata does not expose public URLs.

## Roadmap boundary

Reporter history/detail, responses, additional-information messages and notification tracking remain **11.04**.

Duplicate linking, staff dashboard, assignment/search/filter/analytics/export and bug-wide audit remain **11.05**.
