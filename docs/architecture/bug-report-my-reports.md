# My Bug Reports and Follow-up

Roadmap sub-step **11.04** adds the reporter-facing bug history/detail flow and durable reply/status notifications on top of the 11.01-11.03 bug-report stack.

## Reporter list

`GET /bugs` is authenticated and reads records only through `BugReportService::own()`.

Each row exposes:

- title;
- category;
- current status;
- severity;
- creation time;
- last update time;
- permission-aware detail link.

Category labels are resolved only after the authorized own-report set has been loaded. A user whose create permission is later removed can still read existing reports as long as `bug.report.view_own` remains granted.

## Detail and follow-up

`GET|POST /bugs/{reportId}` always resolves the actor and calls the existing permission-aware `BugReportService::report()` boundary before a mutation.

The detail page shows:

- current status/category/severity/date;
- original summary;
- 11.03 reproduction steps;
- expected and actual results;
- optional path-only source reference;
- authorized private attachments;
- public reporter/staff messages;
- permission-filtered workflow history.

Reporter follow-up information requires both ownership and `bug.report.reply_own`.

Staff replies require `bug.report.view_all` and `bug.report.reply_all`.

Staff status mutations continue to use the existing `BugReportService::changeStatus()` lifecycle and `bug.report.manage` permission. 11.04 does not bypass the 11.01 transition rules.

## Conversation persistence

Migration `20260918024000_bug_report_conversation` adds the append-only `forwext_bug_report_messages` table.

A message stores:

- message id;
- bug report id;
- nullable historical author id;
- author role (`reporter` or `staff`);
- body;
- UTC timestamp.

Account deletion sets the historical author to NULL but does not erase the bug report conversation.

No staff-internal note system is added here because the binding 11.04 scope is reporter-visible responses and follow-up information.

## Permissions

11.04 adds:

- `bug.report.reply_own` — add follow-up information to the actor's own bug reports;
- `bug.report.reply_all` — staff public reply to any authorized bug report.

Built-in user templates receive `reply_own`. Built-in Moderator and Administrator templates additionally receive `reply_all`.

Report ID knowledge is never authorization: read, reply, status and attachment routes re-enter the shared permission engine.

## Notification tracking

The existing durable notification subsystem is reused; no parallel bug-notification table is introduced.

Registered notification types:

- `bug.report.staff_reply` — reporter is notified when staff replies;
- `bug.report.reporter_reply` — current assignee is notified when the reporter adds information;
- `bug.report.status` — reporter is notified on a real status transition.

Notifications use same-origin action paths pointing to `/bugs/{reportId}`, durable repository persistence, grouping/dedupe keys and the existing user channel preferences.

Notification dispatch is an acceleration path. A delivery exception does not roll back already-authoritative report/message/status state.

## Private attachments

`GET /bugs/{reportId}/attachments/{attachmentId}` first authorizes access to the containing report.

The attachment must belong to that report. Before serving private storage, persisted byte length and SHA-256 are checked against the actual stored object.

The response reuses the first-party attachment download response factory, including `nosniff` and private/no-store caching.

## Security

- All POST mutations use the dedicated bug-report CSRF middleware.
- Reporter follow-up has an explicit ownership check in addition to permission checks.
- Staff reply requires all-report access plus its granular permission.
- Status changes remain staff-management actions.
- Detail/list output is escaped.
- Private attachment access cannot be obtained from an attachment id alone.
- Staff-only workflow-history events remain filtered out of reporter views by the existing history repository contract.

## Roadmap boundary

Duplicate detection/linking, staff bug dashboard, assignment UI, filters, search, analytics, export and centralized bug audit remain **11.05**.
