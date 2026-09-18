# Core Moderator/Admin Audit Stream

Sub-step **09.06** implements the shared first-party audit stream for moderator and administrator mutations. The goal is a single permission-aware event model with actor, target, action, before/after, request-id and persistence-time redaction.

## Event model

`AuditEvent` records:

- immutable audit id;
- scope (`moderation` or `administration`);
- actor user id;
- extensible action key;
- typed target and target id;
- optional forum node context;
- optional reason code;
- request id;
- before/after snapshots;
- UTC occurrence time.

Unlike the older moderation-only contract, the core `AuditAction` is not an enum. This lets first-party admin modules add stable namespaced actions without changing a central enum every time.

## Transaction rule

`DatabaseAuditEventStore` refuses writes unless a mutation transaction is active.

`CoreAuditRecorder` can either append inside an existing transaction or wrap an administrative mutation in one transaction and append the event before commit. This keeps the mutated state and its audit record atomic.

The existing moderation stack remains compatible through `DatabaseModerationAuditStore`, which adapts `ModerationAuditEvent` into a core audit event and persists it to the central stream.

## Sensitive-data redaction

`SensitiveAuditRedactor` runs immediately before JSON encoding/persistence. It recursively removes values under sensitive keys, including passwords, passphrases, secrets, tokens, authorization/cookie/session/credential fields, private keys, recovery codes, TOTP/API/access/client secrets, raw IP fields and e-mail fields.

Privacy-safe hashes/fingerprints are not removed merely because they contain the word `ip`; e.g. `ip_fingerprint` can remain available for abuse/security correlation.

Strings are bounded before persistence and snapshots reject unsupported object/resource values.

This is a storage boundary, not only a UI mask: the sensitive value is never written to `forwext_core_audit_events`.

## Upgrade compatibility

Migration `20260918005000_core_audit_stream` creates `forwext_core_audit_events` and imports metadata from the previous `forwext_moderation_audit_events` stream.

Legacy before/after JSON is intentionally replaced with a migration redaction marker during import. Older rows predate the 09.06 redaction contract, so copying their arbitrary snapshot payloads into the new trusted stream would risk carrying historical sensitive values forward. Actor/action/target/reason/request/time metadata remains preserved.

The legacy table is not destructively dropped by this migration.

## Administration integration

`ForumMetadataAdminService` is the first existing ACP management service wired to the core audit contract. It cannot be constructed without an `AuditRecorder`.

Prefix groups, prefixes, custom-field definitions, forum metadata configuration and forum custom-field changes create `administration` scoped events. Where an existing value can be loaded, before/after snapshots are recorded. Request ids can be supplied by the HTTP layer; otherwise a cryptographically random request id is generated.

Future ACP services should depend on the same `AuditRecorder` contract rather than creating feature-specific log tables.

## Moderation integration

Existing thread/post moderation, reports, moderation tasks, approval decisions, discipline and anti-abuse operations already emit `ModerationAuditEvent`. The database moderation adapter now writes those events to the central stream as `moderation` scope.

This preserves existing domain-facing APIs while centralizing persistence and redaction.

## Access and UI

The existing `audit.view` permission gates the read service and native `/moderation/audit` surface. Moderator and Administrator built-in templates receive `audit.view`; normal templates receive deny.

The page supports recent-event browsing plus actor and request-id correlation filters. Before/after JSON is already redacted at storage time and is escaped again during HTML rendering.

The Moderation Workspace only shows the Core Audit Stream link when the backend permission gate allows `audit.view`.

## 09.07 boundary

09.06 does **not** implement the independent/tamper-evident moderation oversight system. Hash chaining, independent review cases, self-record protections and anomaly flags belong to **09.07** and are intentionally kept out of this core operational audit stream.
