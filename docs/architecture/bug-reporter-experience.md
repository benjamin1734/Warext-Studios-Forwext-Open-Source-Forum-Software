# Bug Reporter Experience

Roadmap sub-step **11.04** adds the authenticated **Hata Bildirimlerim** experience on top of the 11.01 bug workflow, 11.02 diagnostic context and 11.03 form/attachment intake.

## User surfaces

- `GET /bugs/my` lists only reports created by the authenticated user.
- `GET|POST /bugs/{reportId}` renders the report detail and accepts reporter additional information or authorized staff responses.
- `GET /bugs/{reportId}/attachments/{attachmentId}` provides permission-aware private attachment download.
- The shared authenticated page shell exposes a second icon-only shortcut for **Hata Bildirimlerim** next to the bug-report icon.
- Successful new submissions redirect to their report detail page.

## Authorization

The backend remains authoritative:

- reporter list uses `BugReportService::own()` and `bug.report.view_own`;
- detail access uses `BugReportService::report()`;
- a normal user cannot open another reporter's record by guessing the report id;
- reporter additional information is accepted only from the original reporter and only while the report is non-terminal;
- staff responses require both `bug.report.view_all` and `bug.report.manage`;
- attachment download first authorizes the parent report, then requires the attachment to belong to that same report.

UI visibility never replaces these checks.

## Conversation and history

11.04 deliberately reuses the append-only bug history stream instead of creating a second conversation silo.

Two public history event types are added:

- `reporter_info_added`;
- `staff_response`.

The event payload stores a bounded, escaped-on-render body. Existing status/category/severity events remain in the same chronology. Staff-only assignment history remains hidden from ordinary reporters through the existing history visibility rule.

Reporter additional information is capped at 10,000 UTF-8 bytes and terminal reports must be reopened before accepting more reporter information.

## Notification tracking

The existing notification engine receives first-party bug definitions under the `bug` category:

- `bug.report.staff_response` -> reporter;
- `bug.report.status` -> reporter;
- `bug.report.reporter_info` -> current assignee when one exists.

Notifications use same-origin action paths pointing back to the bug detail page and deterministic dedupe/group keys. They therefore inherit the existing in-app/email/push preferences, retry, sound and realtime delivery stack rather than adding a separate notifier.

## Attachment integrity

Reporter detail pages may expose attachment links, but bytes remain private. Download rechecks persisted byte length and SHA-256 before delivery. A mismatched or missing object is rejected.

## Persistence

No new migration is required for 11.04:

- report ownership/status already lives in the 11.01 schema;
- reporter/staff messages are append-only history events in the existing history table;
- reproduction/expected/actual fields and attachments already live in the 11.03 intake tables;
- notifications use the existing 07 notification persistence.

## Roadmap boundary

Duplicate detection/linking, staff bug dashboard, staff filters, analytics, export, search and centralized bug audit remain **11.05**.
