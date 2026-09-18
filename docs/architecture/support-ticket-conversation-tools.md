# Support Ticket Conversation and Staff Tools

Sub-step **10.03** extends the 10.01/10.02 ticket core with real requester/staff conversation and operational support tools.

## Conversation model

Ticket messages are append-only records with:

- message id and ticket id;
- optional historical author id;
- author role: `requester` or `staff`;
- visibility: `public` or `internal`;
- body;
- optional canned-response key/title snapshots;
- optional `copied_from_message_id` lineage;
- UTC creation timestamp.

Public messages are visible to the requester and authorized staff. Internal notes are staff-only and the domain rejects any internal message whose author role is not staff.

A closed ticket must be reopened before a new reply. A resolved ticket automatically reopens to `open` when a valid requester/staff reply arrives. This preserves a natural conversation workflow without granting requesters the general ticket-management permission.

The first real staff public reply records `first_responded_at_utc` in the existing SLA snapshot in the same database transaction.

## Permissions

The shared permission engine remains authoritative. 10.03 adds granular staff permissions:

- `support.ticket.reply_all`
- `support.ticket.internal_note`
- `support.ticket.assign`
- `support.ticket.escalate`
- `support.ticket.merge`
- `support.ticket.split`
- `support.canned_response.manage`

Existing `support.ticket.reply_own` remains the requester reply capability.

Ticket visibility is always checked first through the existing own-vs-all boundary. Knowing a ticket id, message id or attachment id is never sufficient to authorize an action.

Built-in moderator/administrator templates receive the staff permissions. Normal user templates receive explicit deny defaults; normal users retain only the existing own-ticket capabilities.

## Status history

`forwext_support_ticket_history` is append-only application history for ticket workflow events:

- created;
- status change;
- assignment;
- escalation;
- merge;
- split creation;
- first staff response.

History entries have public or staff visibility. Requesters never receive staff-only history through the conversation repository.

New tickets created through the 10.02 form receive an initial `created/open` history event. Split-created tickets also receive their own creation event.

This workflow history is separate from the site-wide audit/dashboard work reserved for 10.06.

## Canned responses

Reusable canned responses contain a stable key, title, body, active flag and ordering.

When staff sends a canned reply, the message stores the canned key/title snapshot but stores the actual final message body as well. Later editing a canned response therefore does not rewrite historical messages.

The starter `more_info` response is inserted with `INSERT IGNORE` so administrator edits survive migration re-runs.

## Assignment and escalation

Assignment continues to use the 10.01 ticket assignee field but now requires the dedicated `support.ticket.assign` permission.

The target assignee must still have `support.ticket.view_all`, preventing assignment to accounts that cannot open the staff ticket.

Escalation is an explicit level 1-5 state. Levels may only increase. The database upsert also uses a monotonic `GREATEST` guard so a lower concurrent write cannot replace a higher escalation level.

## Merge

Merge is deliberately non-destructive:

- both tickets must be active;
- source and target must belong to the same requester;
- source ticket is closed;
- all source messages, including staff-only internal notes, are copied into the target with new message ids;
- every copied message keeps `copied_from_message_id` pointing to the original;
- the bounded one-shot merge refuses conversations at the 500-message safety ceiling rather than silently truncating a partial merge;
- the original source conversation is not deleted;
- a typed `merged_into` relation and history entries are added.

Attachments and original intake data remain on the source ticket. The target detail page exposes the relation so authorized viewers can open the preserved source record and its files. This avoids silently duplicating stored objects or losing provenance.

The same-requester requirement prevents merge from becoming a cross-account information-disclosure path.

## Split

Split may use only a **public** message from the source ticket. Internal notes cannot become requester-visible content through split.

The operation:

- creates a new open ticket for the same requester;
- carries category, current priority and current assignee;
- calculates fresh SLA due dates from the current category policy;
- copies the selected public message as both initial intake description and a lineage-preserving conversation message;
- carries the optional context link;
- creates a typed `split_from` relation and public history entries.

The source ticket and original message remain unchanged.

## Notifications

The durable first-party notification stack is reused for:

- staff reply to requester;
- requester reply to current assignee;
- assignment;
- requester-visible status change;
- split-created ticket.

Notification failures are treated as a delivery acceleration failure and do not roll back already committed authoritative ticket state.

## Native ticket detail route

`GET|POST /support/tickets/{ticketId}`

provides the conversation surface. It renders:

- current ticket metadata;
- original description/dynamic values/context;
- support attachments;
- public conversation;
- public status history;
- staff-only notes/history/escalation/canned responses where authorized;
- granular staff actions.

All output is escaped and POST mutations use the dedicated support CSRF middleware.

Assignment accepts a username in the UI and resolves the user server-side before the domain re-checks staff eligibility.

## Attachment downloads

`GET /support/tickets/{ticketId}/attachments/{attachmentId}`

rechecks ticket visibility before reading private storage. The requested attachment must belong to that ticket and stored bytes must match persisted size and SHA-256 before a download response is returned.

## Persistence

Migration `20260918013000_support_conversation_tools` adds:

- `forwext_support_canned_responses`;
- `forwext_support_ticket_messages`;
- `forwext_support_ticket_history`;
- `forwext_support_ticket_escalations`;
- `forwext_support_ticket_relations`.

The migration is additive/idempotent and does not reset existing tickets or forum data.

## Roadmap boundary

10.03 does not implement FAQ content/recommendation behavior (10.04-10.05) or the final user "My Tickets" list, staff dashboard, SLA statistics, category reporting and support-wide audit surface (10.06).
