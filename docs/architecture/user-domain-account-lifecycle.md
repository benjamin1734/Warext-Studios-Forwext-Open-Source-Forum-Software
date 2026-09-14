# Forwext User Domain & Account Lifecycle Contract

Status: **Normative implementation baseline**  
Roadmap step: **04.01 — User domain ve account lifecycle**

## Domain boundary

`User` is the canonical identity/account aggregate. Registration, authentication, profile UI, moderation and permissions call this domain; they do not create alternate user-state rules in controllers.

04.01 intentionally establishes account identity/state/data semantics only. Registration policy/Turnstile/email verification is 04.02, login credentials/sessions are 04.03, device security is 04.04, OAuth is 04.05, profile surfaces are 04.06–04.08 and global/node authorization is Main Step 05.

## User identity

User ids are CSPRNG-generated 128-bit lowercase hexadecimal identifiers. Database/API code must treat them as opaque values. Guessing or possessing a user id never grants profile/account access.

The user table maintains independent display/canonical identity values for username and email. Canonical keys are protected by database unique indexes so application-level prechecks are not the only duplicate defense.

## Username normalization

`Username` enforces:

- valid UTF-8;
- 3–32 Unicode characters;
- letters/numbers plus interior dot, underscore and hyphen;
- alphanumeric first/last character;
- a deterministic case-insensitive canonical lookup key.

ASCII usernames require no optional extension and use lowercase canonical keys.

Non-ASCII usernames fail closed unless the runtime can provide both:

- Intl `Normalizer` NFKC-compatible normalization;
- Mbstring Unicode case folding.

This avoids accepting Unicode identities on a server that cannot generate a deterministic uniqueness key. The capability matrix reports `extension.intl`, `extension.mbstring` and `function.mb_convert_case` so installer/ACP can explain this limitation. These remain optional for the minimum ASCII-compatible cPanel profile.

Canonical username keys use a binary database collation. Case/style-only display changes may keep the same canonical identity key without creating a duplicate account.

## Email normalization

`EmailAddress` uses an intentionally case-insensitive account-identity policy:

- surrounding whitespace/control bytes are rejected;
- local part uses a bounded ASCII dot-atom-compatible character set;
- local part is stored lowercase;
- domain is lowercase;
- internationalized domain names require Intl `idn_to_ascii` and are stored as ASCII/Punycode;
- the complete canonical address is limited to 254 bytes;
- canonical email is the unique lookup key.

The account-identity policy is deliberately stricter than the complete SMTP grammar. Quoted local parts and SMTPUTF8 local parts are not accepted by the baseline identity model.

Email existence checks are repository/application operations and must not be exposed to unauthenticated clients as an enumeration oracle. Registration/recovery flows in 04.02/04.03 define externally visible responses.

## Locale and timezone

`UserLocale` stores a bounded canonical BCP-47-style identifier (language lower-case, script title-case, region upper-case). It does not depend on the optional Intl extension.

`UserTimezone` accepts only named IANA identifiers (plus `UTC`), not arbitrary fixed offsets. This preserves daylight-saving behavior and stable scheduling/display semantics.

## Account states

Canonical states:

- `pending_email` — awaiting email verification;
- `pending_approval` — verified/created but awaiting staff/policy approval;
- `active` — normal authentication-capable account;
- `suspended` — temporary moderation restriction;
- `banned` — moderation ban;
- `deactivated` — disabled account;
- `deletion_pending` — account is awaiting a later deletion/purge workflow.

Only `active` is considered normally authentication-capable by the domain helper. Authentication flows may expose narrowly scoped verification/recovery actions to other states without treating them as active sessions.

`UserStatusTransitionPolicy` defines legal state edges. Illegal transitions throw before mutation. Authorization for *who* may perform a legal transition is not embedded in the entity; application services and the shared permission engine must authorize it.

`deletion_pending` is a lifecycle marker, not immediate data erasure. The later account/privacy deletion workflow decides retention/purge behavior and must account for moderation/legal/audit requirements.

## Custom fields

The user aggregate stores typed per-user custom-field values keyed by validated internal field names. Supported stored types are string, integer, boolean and JSON-array data, with bounded encoded size.

This is the **value/persistence layer**. Field definitions, presentation, editability, visibility and permission rules must be supplied by the owning profile/ACP configuration layer before calling the aggregate. A custom-field key existing in the aggregate does not make it user-editable or publicly visible.

Custom-field values are not copied into account history entries.

## Account history

Every aggregate mutation creates a typed `UserHistoryEntry` and a lightweight domain event. History records contain:

- event type;
- changed field names;
- UTC time;
- optional actor user id;
- optional from/to account states;
- optional machine reason code.

History intentionally does **not** duplicate old/new email addresses, usernames or custom-field values. This reduces sensitive-data duplication while still preserving the fact, actor, reason and state transition required for account/audit workflows.

The dedicated moderation/audit systems later may retain their own separately authorized evidence where required.

## Persistence and concurrency

`DatabaseUserRepository` persists user row, custom-field values and pending history in one transaction.

New users start at aggregate version `0`; first successful persistence writes version `1`. Existing updates use optimistic concurrency:

`WHERE user_id = :user_id AND version = :expected_version`

A zero-row update throws `UserConcurrencyException`; it never silently overwrites a newer account state. Persisted objects with no pending domain changes are a no-op and do not increment the version.

Username/email unique indexes are the final race-safe identity constraint. Registration services must translate duplicate-key failures into non-enumerating product behavior rather than trusting only a previous availability query.

## Database schema

Core migration `20260914233000_user_domain` creates:

- `forwext_users`;
- `forwext_user_custom_field_values`;
- `forwext_user_history`.

`forwext_users` contains binary-collated canonical username uniqueness and ASCII-binary canonical email uniqueness. User/custom-field/history rows use the opaque 128-bit user id. Custom-field/history rows cascade with account-row deletion; independent moderation/audit retention is a different subsystem.

## Security / permission review

- Entity ids, username keys, email keys and history rows grant no authorization.
- Account-state transition legality is separate from actor permission.
- Username/email lookup methods are internal repository capabilities and must not become direct public enumeration endpoints.
- Sensitive previous identity/custom-field values are not copied into account history.
- Custom-field visibility/edit permission is enforced outside the storage aggregate by shared policy/permission layers.
- Optimistic concurrency prevents lost updates between admin/user/background operations.
- Password hashes, sessions, reset tokens, 2FA secrets and OAuth credentials are intentionally absent from 04.01 tables; their dedicated security steps own them.

## Acceptance status

04.01 is complete when account states/transitions, canonical username/email identities, locale/timezone values, typed user custom-field storage, mutation history/domain events, optimistic repository persistence, unique identity constraints and migration/domain/repository tests exist as real code with the security/permission boundaries above.
