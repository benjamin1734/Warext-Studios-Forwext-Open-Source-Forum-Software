# Approval / Moderation Queue Architecture

Sub-step **09.03** establishes one first-party approval queue contract for content domains that support a real pending-moderation lifecycle.

## Shared queue contract

`ApprovalQueueRegistry` owns source-type registration and dispatch. A provider may own one or more stable content type identifiers and contributes:

- the permission-filtered pending count,
- the latest permission-filtered pending items,
- bulk approve/reject execution for selections it owns.

The first concrete provider owns `forum.thread` and `forum.post`. Profile, portfolio, marketplace and later first-party domains plug into the same registry only when those domains have real persisted moderation states; no placeholder rows, fake URLs or parallel approval applications are created ahead of their roadmap steps.

## Authorization and IDOR/BOLA boundary

- `moderation.access` is required before the common queue can be read.
- `moderation.manage` is required before any common queue mutation can be dispatched.
- Forum candidates are derived on the server and require `forum.view`, the relevant `forum.thread.moderate` / `forum.post.moderate` permission and `forum.moderation.bulk` for the same node.
- Client selection tokens identify a candidate but never grant access. `ContentModerationService` re-loads each selected thread/post and re-checks the node-scoped permissions before persistence.
- Bulk approval/rejection locks the current database row. Approval-queue actions require the stored state to still be `pending`; a stale request fails instead of overwriting a newer moderator decision.
- Mutation requests reuse the moderation same-origin guard (`X-Forwext-Moderation`, configured Origin and Sec-Fetch-Site validation).

## Audit and reason model

The queue reuses `ModerationReasonCode`, `ModerationRequestId` and the existing moderation audit stream. `approve` and `reject` are first-class moderation actions for threads and posts. Every changed item receives its normal content audit event and bulk operations retain their existing summary audit event.

The native UI offers bounded reason codes instead of accepting an arbitrary audit code. Request correlation uses the existing HTTP request-id attribute where available.

## UX and deployment

`GET /moderation/approval` provides the native PHP queue. Authorized managers can select up to 100 records and submit an approve/reject bulk action; read-only moderation users do not receive mutation controls. The workspace approval section links to this shared queue rather than maintaining a second queue implementation.

No migration is required for 09.03: the implementation reuses the existing thread/post moderation states, permission tables and audit storage. It adds no Node, Redis, daemon, Supervisor or worker requirement to the normal cPanel profile.
