# Bug Staff Dashboard, Duplicate Workflow and Audit

Roadmap sub-step **11.05** completes the first-party bug-report management workspace on top of the 11.01–11.04 workflow.

## Staff dashboard

Authenticated users with `bug.report.view_all` may open `/bugs/staff`.

The dashboard provides:

- queue totals for new, in-review, resolved, rejected and duplicate reports;
- active unassigned count;
- title/summary staff search;
- status, severity, category and assignee/unassigned filters;
- reporter and assignee context;
- category-level active/terminal/duplicate analytics;
- centralized bug-scoped audit history when `bug.audit.view` is granted;
- filtered CSV export when `bug.report.export` is granted.

The staff dashboard is `private, no-store` and returns `X-Robots-Tag: noindex, nofollow`.

## Backend permissions

Existing workflow permissions remain authoritative:

- `bug.report.view_all` — staff queue/detail access;
- `bug.report.manage` — category, severity, lifecycle and duplicate decisions;
- `bug.report.assign` — assignment changes;
- `bug.report.reply_all` — staff public responses.

11.05 adds:

- `bug.report.export` — filtered CSV export;
- `bug.audit.view` — bug-scoped central audit visibility.

Built-in moderator and administrator templates receive the two new permissions. Ordinary member templates receive deny rules.

## Duplicate detection

Duplicate detection is advisory only.

`BugDuplicateDetector` tokenizes the report title and summary, computes bounded similarity using title/summary Jaccard overlap and a same-category boost, then returns ranked candidates over a minimum threshold.

A similarity suggestion never changes report state.

A staff member with `bug.report.manage` must explicitly select a canonical report. The backend then atomically:

1. validates the source/canonical pair;
2. rejects self-links and a canonical report that is already itself marked duplicate;
3. transitions the source report to `duplicate` when lifecycle rules allow it;
4. persists the canonical relation;
5. appends a central audit event;
6. emits the normal reporter status notification.

Terminal reports must be reopened before being newly linked as duplicate.

## Duplicate persistence

Migration `20260918025000_bug_staff_workflow` creates `forwext_bug_report_duplicates`.

The duplicate report id is the primary key so one report can have at most one canonical target. Foreign keys protect source, canonical and staff actor integrity.

Canonical reports are protected with `ON DELETE RESTRICT`, while deleting a duplicate source cascades its relation.

## Search and filters

Staff search is intentionally scoped to the bug dashboard instead of exposing private bug reports through public/member search scopes.

Search operates on title and summary using bound SQL parameters. LIKE wildcard characters are escaped before the query is built.

Filters are typed for status and severity. Category values follow the existing bug category key rules. Assignee filters resolve a real username server-side, while the literal `unassigned` selects active unassigned records.

## Assignment and workflow controls

The existing bug detail page becomes the staff management surface when the viewer has staff access.

Depending on backend permissions it exposes:

- assignment/unassignment by validated username;
- status changes;
- severity changes;
- category changes;
- duplicate candidate review/linking.

The UI is only a convenience surface. Every mutation re-runs the corresponding backend permission checks.

## Central audit

`AuditScope::Bug` records first-party bug operations in the existing immutable core audit stream.

11.05 records:

- reporter follow-up messages;
- staff replies;
- assignment;
- status changes;
- severity changes;
- category changes;
- duplicate links;
- CSV exports.

Audit events contain identifiers and state transitions, not message bodies or uploaded attachment contents.

## CSV export

Exports are limited to at most 1,000 rows per request and honor the same staff filters.

The exporter writes UTF-8 CSV with a BOM and explicitly disables legacy backslash escaping. Cells beginning with `=`, `+`, `-` or `@` are prefixed with an apostrophe to reduce spreadsheet formula-injection risk.

Exports are permission-gated, `private, no-store`, `nosniff`, and recorded in the central bug audit.

## Roadmap boundary

11.05 completes the Hata Bildirimleri main step. The next roadmap work starts at **12.01** and must be taken from the binding master development plan.
