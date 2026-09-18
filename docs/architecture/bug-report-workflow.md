# Bug Report Domain and Workflow

Roadmap sub-step **11.01** introduces the first-party bug-report aggregate and workflow. It deliberately stops before the automatic technical-context collector (11.02), page-level report form/attachments (11.03), reporter-facing "Hata Bildirimlerim" experience (11.04), and the staff dashboard/duplicate-detection/search/export/audit system (11.05).

## Core aggregate

A `BugReport` contains:

- stable report id;
- category key;
- nullable historical reporter user id;
- optional assigned staff user id;
- title and short summary;
- typed severity;
- typed workflow status;
- finalized timestamp for terminal states;
- created/updated UTC timestamps;
- optimistic-lock version.

Reporter/assignee foreign keys use `ON DELETE SET NULL`, so account deletion never deletes the historical bug record.

## Severity

The first-party severity set is:

- `low`
- `medium`
- `high`
- `critical`

Categories provide a default severity, but the create service may receive an explicit typed severity. Authorized staff can later correct severity through the same version-checked workflow.

## Categories

Bug categories are configurable first-party records with:

- stable key;
- label/description;
- default severity;
- ordering;
- active state.

Starter categories are inserted with `INSERT IGNORE` so later administrator edits survive migration re-runs:

- general
- frontend
- backend
- performance
- security

Inactive categories cannot be selected for new reports or category-change operations.

## Workflow

Statuses are:

- `new`
- `in_review`
- `resolved`
- `rejected`
- `duplicate`

`new` and `in_review` are active states. `resolved`, `rejected`, and `duplicate` are terminal states and require `finalized_at_utc`.

Terminal states can only be reopened to `new`; they cannot jump directly back into another working state. Reopening clears the finalized timestamp.

The `duplicate` status exists in 11.01 because it is part of the required lifecycle. Automatic duplicate discovery, linking, dashboard filters, analytics, export, search, and audit are intentionally reserved for 11.05.

## Assignment

Assignment has a dedicated `bug.report.assign` permission rather than inheriting all workflow-management powers.

The service validates that an assignee actually has `bug.report.view_all`. A report in a terminal state must be reopened before it can be assigned.

## Permission and IDOR/BOLA boundary

The domain uses the shared permission engine:

- `bug.report.create` — create a bug report;
- `bug.report.view_own` — read/list the actor's own bug reports;
- `bug.report.view_all` — read reports from other users;
- `bug.report.manage` — manage categories, severity, and lifecycle;
- `bug.report.assign` — assign/unassign reports.

Knowing a report id does not grant access. `BugReportService::report()` checks ownership first and otherwise requires `bug.report.view_all`.

Every staff mutation requires `bug.report.view_all` in addition to its action permission, preventing custom roles with an isolated mutation flag from editing arbitrary report ids they cannot read.

Built-in normal user templates receive create/view-own access. Moderator/Administrator templates receive all bug-report staff permissions.

## Append-only tracking history

`forwext_bug_report_history` records:

- created;
- status changed;
- assignment;
- severity changed;
- category changed.

History is append-only at the repository boundary.

Entries have `public` or `staff` visibility. Creation, lifecycle, severity, and category history is reporter-visible. Assignment history is staff-only so internal staffing information is not exposed to the reporter by default.

The reporter-facing UI in 11.04 can therefore reuse this history safely without inventing a parallel workflow log.

## Concurrency

Bug-report mutations use the same optimistic-lock approach as support tickets:

- every row has a positive `version`;
- mutations match the expected version;
- successful mutation increments the version;
- stale writes return a controlled conflict instead of silently overwriting a newer staff action.

Report mutation and its history append occur in the same database transaction.

## Persistence

Migration `20260918021000_bug_report_workflow` creates:

- `forwext_bug_report_categories`
- `forwext_bug_reports`
- `forwext_bug_report_history`

It also installs built-in bug-report permission defaults and indexes for reporter history, staff queue, category, assignee, and history traversal.

The migration is additive and idempotent. It does not reset existing user, forum, support, or moderation data.

## Roadmap boundary

11.01 intentionally does not include:

- URL/route/browser/device/request-id diagnostic capture — 11.02;
- page-level bug form, reproduction steps, expected/actual result, screenshots/files — 11.03;
- reporter-facing list/detail/additional-information/notification flows — 11.04;
- staff dashboard, automatic duplicate detection/linking, filters, analytics, export, search, and centralized bug audit — 11.05.
