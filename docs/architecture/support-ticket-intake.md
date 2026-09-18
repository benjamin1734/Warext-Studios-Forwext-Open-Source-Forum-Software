# Support Ticket Intake and Creation UX

Sub-step **10.02** builds the authenticated ticket-creation flow on top of the 10.01 support-ticket core.

## Native route and CSRF

The first-party route is:

`GET|POST /support/new`

It uses the shared native PHP router, authenticated viewer resolution and a dedicated CSRF scope. GET issues a token and renders the form; POST is rejected by middleware before handler execution when the token/context is invalid.

The UI uses progressive disclosure:

1. choose a support category;
2. render only that category's active dynamic fields;
3. enter subject and description;
4. optionally expand context linking;
5. optionally attach files.

No JavaScript or Node runtime is required.

## Dynamic fields

`SupportFieldDefinition` supports:

- text;
- textarea;
- select;
- checkbox.

Definitions contain stable category/field keys, label, required state, choices, max length, help text, ordering and active state.

Server-side validation reloads active definitions from the database. Unknown submitted `field_*` keys are rejected rather than ignored. Required/type/choice/length validation is therefore independent of browser HTML.

Stored ticket values snapshot their field key, field type and JSON scalar value. They are not foreign-keyed to the mutable definition row, so later field-definition changes do not erase historical ticket input.

## Description and intake metadata

The user description is stored in a one-to-one `forwext_support_ticket_intake` row rather than widening the 10.01 lifecycle aggregate with form-specific data.

The default policy allows 1-10000 UTF-8 bytes.

## Context linking

A ticket may contain one optional typed context link:

- `thread`
- `account`
- `marketplace_listing`

Thread links resolve the real thread and require the submitting actor to have `forum.view` on the thread's forum node. The stored label is a thread-title snapshot.

Account links permit self-linking. Linking another account requires `support.ticket.view_all`; the stored label is the username only and does not expose e-mail or other private account data.

The marketplace domain is intentionally not fabricated before its roadmap step. `marketplace_listing` therefore stores a canonical 32-hex listing reference plus a generic label without dereferencing or exposing marketplace data. The resolver contract allows the future marketplace module to replace that opaque resolver with real existence/access checks without changing support-ticket storage.

## Attachments

Support attachments reuse the existing hardened attachment primitives rather than the forum/post attachment ownership tables:

- `VerifiedUploadedAttachmentReader` verifies the source is a real PHP HTTP upload and enforces the request byte limit.
- `AttachmentInspector` signature-checks allowed image/PDF/ZIP/text formats, applies image safety limits and metadata stripping.
- `AttachmentFilename` normalizes client filenames.
- the shared private `StorageDriver` stores sanitized bytes.

Support-specific metadata is written to `forwext_support_ticket_attachments`, because forum attachments are structurally bound to forum nodes/posts and their permission model must not be weakened.

Default policy: maximum 5 files per submission, each using the shared 25 MiB per-file quota.

Storage paths are private and ticket-scoped:

`support/tickets/{ticketId}/{attachmentId}/{sha256}.{extension}`

If database persistence fails after an object is written, the submission service deletes already-written storage objects as compensation.

Attachment ownership may become NULL after account deletion; the ticket relation remains and future download authorization is ticket-based.

## Spam and rate limiting

`DatabaseSupportSubmissionRateLimiter` uses fixed database buckets with atomic increment/read behavior.

Default authenticated limits are:

- 5 submissions per user per hour;
- 20 submissions per user per day;
- at most 2 equivalent normalized payloads per 15 minutes.

Rate-limit storage never contains raw user ids, raw form text or IP addresses. User and duplicate-content keys are SHA-256 fingerprints.

The duplicate fingerprint covers normalized category, subject, description, validated dynamic values and optional context reference.

## Atomicity

After validation/rate limiting/file inspection, ticket + intake + field values + context + attachment metadata are persisted in one database transaction.

10.02 updates `SupportTicketService::create()` so it participates in an existing transaction rather than starting a nested one.

Private storage is not database-transactional, so written objects are tracked and deleted on any exception before the transaction can complete.

## Schema

Migration `20260918012000_support_ticket_intake` adds:

- `forwext_support_category_fields`
- `forwext_support_ticket_intake`
- `forwext_support_ticket_field_values`
- `forwext_support_ticket_context_links`
- `forwext_support_ticket_attachments`
- `forwext_support_submission_rate_limits`

The migration is additive and idempotent.

## Roadmap boundary

Ticket conversation, requester/staff messages, internal notes, canned responses, escalation, merge/split and status history remain 10.03. FAQ remains 10.04-10.05. The full staff dashboard, My Tickets reporting surface, SLA statistics and support audit remain 10.06.
