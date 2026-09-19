# Cross-system content governance integration

Roadmap step 12.07 makes AI moderation, spellcheck, the user content manager and thread freshness share the same backend authorization and central audit boundaries.

## Authorization contract

Runtime permission checks remain backend-authoritative through PermissionGate or PermissionAuthorizer. UI visibility is presentation only.

- AI moderation human overrides use `ai.moderation.override`; moderation feedback uses `ai.manage`.
- Spellcheck uses `spellcheck.use`, `spellcheck.dictionary.manage_own` and `spellcheck.dictionary.manage_site`.
- User content management uses `content_manager.access` and `content_manager.execute`; asynchronous execution re-checks `content_manager.execute`.
- Thread freshness uses the canonical node-scoped keys `forum.thread.freshness.renew_own`, `forum.thread.freshness.renew_any`, `forum.thread.freshness.review` and `forum.thread.freshness.manage_policy`.

The older catalog-only aliases `freshness.renew_own`, `freshness.review` and `freshness.manage` are retired. Migration `20260919110000_content_governance_integration` copies non-conflicting legacy global/node grants to the canonical keys, keeps an existing canonical rule when both exist, removes the shadow aliases and verifies that no alias rules remain.

## Central audit contract

Human state-changing operations fail closed when a central AuditRecorder is not configured. The native PHP runtime wires one CoreAuditRecorder backed by the shared database audit stream.

Audited operations include AI moderation override/feedback actions, spellcheck dictionary mutations, content-manager operation enqueue, thread-freshness policy changes, renewal, review resolution and moderator-triggered maintenance.

HTTP mutation handlers propagate the RequestIdMiddleware request id into audit events. Audit payloads use identifiers, state and fingerprints rather than raw dictionary words, AI feedback notes, content bodies, credentials or secrets.

Scheduled freshness maintenance remains a system operation because the current core audit schema requires a real user actor. The moderator-triggered maintenance path is actor-aware and only mutates threads in forums where the actor currently has `forum.thread.freshness.review`, preventing a permission grant on one forum from becoming cross-forum maintenance authority.

Where a state mutation can be represented as one transaction, AuditRecorder::mutate keeps the business change and audit append inside that transaction. The existing SensitiveAuditRedactor remains a second defensive layer.
