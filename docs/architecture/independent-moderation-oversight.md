# Independent Moderation Oversight

Sub-step **09.07** adds an oversight system that is deliberately separate from the operational Core Audit Stream introduced in 09.06.

## Trust boundary

Operational audit answers "what did the application record?". Independent moderation oversight adds tamper evidence for moderation events recorded **after the oversight chain is activated**.

Each moderation mutation is written, in the same database transaction, to:

1. the operational `forwext_core_audit_events` stream; and
2. the independent `forwext_moderation_oversight_entries` chain.

If either write fails, the enclosing moderation mutation is rolled back.

The oversight entry table exposes no update/delete repository API. Review cases and anomaly flags live in separate tables and never rewrite the chained event.

## Hash chain

Every independent entry stores:

- monotonic sequence number;
- source moderation audit id;
- actor/action/target/request-id metadata;
- canonical redacted payload JSON;
- SHA-256 payload hash;
- previous chain hash;
- SHA-256 chain hash;
- event timestamp.

The chain hash is derived from:

`SHA256(sequence : previous_hash : payload_hash)`

A single `moderation` chain-state row is locked with `SELECT ... FOR UPDATE` before append. This serializes concurrent moderation audit writes and prevents two valid entries from branching from the same previous hash.

The payload uses the same sensitive-data redaction boundary as 09.06 before hashing, so passwords, secrets, tokens, raw IP/e-mail values and similar material are neither stored nor hashed into the independent payload.

## Verification

`ModerationOversightVerifier` walks the chain in ascending pages rather than loading the full history into memory.

It verifies:

- sequence continuity;
- each entry's `previous_hash`;
- the hash of stored payload JSON;
- the calculated chain hash;
- final chain-state sequence/hash consistency.

Any changed payload, removed/reordered entry, rewritten hash, broken previous link or mismatching chain state produces a failed verification result.

Full verification is explicit in the UI rather than running on every page load.

## Historical boundary

The independent chain starts at migration `20260918010000_independent_moderation_oversight`.

Existing pre-09.07 moderation/core audit events are **not** backfilled into the hash chain. A hash created today cannot prove that an old record was unchanged before today. Those events remain available in the operational 09.06 audit stream but are correctly distinguished from post-activation tamper-evident entries.

## Self-review protection

`ModerationOversightService` requires `audit.review` for review/flag mutations and loads the immutable source chain entry before any mutation.

If the current reviewer is the actor recorded by that moderation event, the service rejects:

- opening a review case;
- resolving a review case;
- adding an anomaly flag;
- resolving an anomaly flag.

This prevents a moderator from changing the review state around their own moderation record. The underlying chain entry itself has no mutation API regardless of permission.

## Review cases and anomaly flags

Review cases contain source audit id, opener, summary, status and resolution actor/time/text.

Anomaly flags contain source audit id, stable flag type, severity, details and resolution metadata.

These are annotations around the immutable chain, not replacements for the event itself. Database updates use conditional open/unresolved predicates so stale double resolution is rejected.

## Permissions

- `audit.view` — read the independent stream and request chain verification.
- `audit.review` — open/resolve review cases and anomaly flags.

Built-in Moderator and Administrator templates receive `audit.review`; ordinary templates receive deny. Self-review protection still applies to every reviewer.

No extra export behavior is introduced by 09.07.

## Native UI

`/moderation/oversight` provides:

- recent independent entries;
- explicit full-chain verification;
- open review cases;
- open anomaly flags;
- review/flag controls for authorized reviewers.

Mutations reuse the existing same-origin moderation guard and all rendered fields are escaped.

## Deployment

Migration `20260918010000_independent_moderation_oversight` is additive and idempotent. It creates chain state, immutable entries, review cases and anomaly flags without resetting existing data.

No Redis, Node.js, Docker, Supervisor or permanent worker is required.
