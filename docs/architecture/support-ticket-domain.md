# Support Ticket Domain

Sub-step **10.01** establishes the internal support-ticket domain. It intentionally stops before the dynamic form/attachment UX in 10.02, conversation/staff tools in 10.03, FAQ in 10.04-10.05 and dashboard/reporting/audit surfaces in 10.06.

## Domain model

A support ticket contains:

- immutable ticket id;
- category key;
- requester user id, nullable only so historical tickets survive account deletion;
- optional assigned support user;
- subject;
- priority;
- lifecycle status;
- SLA metadata;
- created/updated timestamps;
- optimistic-lock version.

The initial lifecycle states are:

- `open`
- `in_progress`
- `waiting_requester`
- `resolved`
- `closed`

Allowed transitions are encoded in `SupportTicketStatus::canTransitionTo()`. Closed tickets can only reopen to `open`; they cannot jump directly into another working state.

## Categories and SLA policy

`SupportCategory` provides:

- stable category key;
- label/description;
- default priority;
- optional first-response SLA minutes;
- optional resolution SLA minutes;
- ordering and enabled state.

When a ticket is created, SLA due dates are calculated once and copied onto the ticket as a snapshot. Later category-policy edits therefore do not rewrite historical ticket targets.

`SupportSlaMetadata` stores:

- first-response due time;
- resolution due time;
- actual first-response time;
- actual resolution time.

It also exposes deterministic first-response and resolution breach checks for the reporting layer planned in 10.06.

The starter `general` category uses a normal priority, 24-hour first-response target and 72-hour resolution target. It is an editable default, not a hard-coded business rule.

## Permissions and IDOR/BOLA boundary

The service enforces permissions server-side:

- `support.ticket.create` — create a ticket;
- `support.ticket.view_own` — list/read the actor's own tickets;
- `support.ticket.view_all` — read the staff queue and tickets owned by other users;
- `support.ticket.manage` — category management, assignment, priority, lifecycle and SLA first-response marker.

`support.ticket.reply_own` already exists in the shared permission catalog and receives safe template defaults here, but reply behavior itself belongs to 10.03.

A requester cannot read another user's ticket merely by knowing its id. Assignment also validates that the target staff account has `support.ticket.view_all`.

## Persistence and concurrency

Migration `20260918011000_support_ticket_domain` creates:

- `forwext_support_categories`
- `forwext_support_tickets`

Requester and assignee foreign keys use `ON DELETE SET NULL` so account deletion does not erase support history. Category deletion is restricted while tickets reference it.

Ticket mutations use an integer `version` column. Repository updates require the expected version and increment it atomically; stale writes fail with a controlled conflict rather than silently overwriting a newer staff action.

Indexes cover requester history, active staff queue, assignee queue and first-response SLA lookup.

## Lifecycle service

`SupportTicketService` provides the 10.01 application boundary for:

- listing available categories;
- category persistence for authorized staff;
- ticket creation;
- own-ticket retrieval;
- permission-aware single-ticket retrieval;
- staff active queue;
- assignment;
- priority changes;
- status transitions;
- first-response SLA timestamp recording.

Resolved/closed tickets retain resolution metadata. Reopening clears the previous resolution marker so a new active lifecycle is represented consistently.

## Deliberate boundaries

10.01 does not add:

- dynamic category fields or attachments — 10.02;
- requester/staff messages, internal notes, canned replies, escalation, merge/split or status history — 10.03;
- FAQ content — 10.04;
- FAQ recommendation/draft links — 10.05;
- native support dashboards, SLA reporting or final support audit surface — 10.06.

The permission, schema and lifecycle choices in this step are designed so those later features can attach without replacing the ticket core.
