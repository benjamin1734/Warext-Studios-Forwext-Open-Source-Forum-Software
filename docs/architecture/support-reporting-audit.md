# Support Panel, My Tickets, Reporting and Audit

Roadmap sub-step **10.06** completes main step 10 by adding user/staff read models, SLA reporting and central support audit integration on top of the existing ticket/conversation tables.

## My Tickets

`GET /support/tickets`

requires authentication plus `support.ticket.view_own`.

The page calls the existing requester-scoped repository query using the authenticated actor id. No user id is accepted from query/path/body input, so the list cannot be widened by IDOR/BOLA parameter manipulation.

The page exposes ticket subject, category, status, priority and updated time with links to the existing permission-aware ticket detail route.

## Staff dashboard

`GET /support/staff`

requires:

- `support.ticket.view_all`
- `support.report.view`

The dashboard contains:

- total/active/resolved/closed counts;
- first-response SLA breach count;
- resolution SLA breach count;
- average first-response time;
- average resolution time;
- bounded active queue;
- per-category totals/active/resolved-or-closed counts;
- per-category SLA breaches and average response/resolution durations.

The aggregate queries run directly over the normalized ticket/category schema. No parallel analytics state or mandatory background aggregation worker is introduced.

## SLA semantics

A first-response SLA is breached when its due timestamp exists and either:

- there is no first response and the due timestamp is before the reporting time; or
- the actual first response occurred after its due timestamp.

Resolution SLA uses the equivalent rule with `resolved_at_utc`.

Average first-response time is measured from ticket creation to first staff response. Average resolution time is measured from ticket creation to the stored resolution timestamp.

## Support audit

The project already owns one central audit stream from 09.06. 10.06 extends `AuditScope` with:

`support`

Ticket mutations write to the existing `forwext_core_audit_events` table via `CoreAuditRecorder`; no separate support-audit table exists.

Audited operations include:

- ticket creation;
- public reply;
- internal note;
- canned-response save;
- assignment;
- status change;
- escalation;
- merge;
- split.

Audit entries are appended in the same DB transaction as the authoritative mutation whenever a transaction exists. Ticket message/internal-note bodies are deliberately excluded from audit snapshots. Audit snapshots contain bounded operational metadata such as message id, role, visibility, status, assignment, escalation level and relation ids.

The web handlers generate one audit request id for each support service instance/request.

## Audit visibility

The staff dashboard always requires `support.report.view`. Its audit section additionally requires:

`support.audit.view`

Without that permission, the reporting service does not even query support audit rows.

The read model selects only rows whose central scope is `support`.

## Permissions

10.06 adds:

- `support.report.view`
- `support.audit.view`

Built-in moderator/administrator templates receive allow. Normal user templates receive deny.

Normal users continue to use `support.ticket.view_own` for My Tickets.

## Persistence

Migration `20260918020000_support_reporting_audit` adds only the two permission definitions/template defaults. Reporting reuses existing support indexes and the 09.06 central audit table, so no redundant reporting/audit schema is created.

## Security

- My Tickets derives requester identity exclusively from the authenticated session.
- Staff reporting requires explicit backend permissions.
- Audit visibility is independently gated from general reporting.
- HTML output escapes ticket/category/audit-derived text.
- Ticket detail authorization remains the final boundary when opening an item from either list.
- No message body or internal note body is duplicated into central audit.
- No Node/Redis/worker/daemon dependency is introduced.

With 10.06 complete, main roadmap step 10 is complete and work advances to 11.01.
