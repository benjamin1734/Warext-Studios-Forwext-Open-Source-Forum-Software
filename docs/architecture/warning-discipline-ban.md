# Warning, Discipline & Ban Architecture

Sub-step **09.04** implements the first-party warning, restriction, suspension and ban domain. It reuses the shared user, permission, notification, moderation-audit and native PHP UI infrastructure.

## Warning definitions and points

Warning definitions are persisted in `forwext_warning_definitions`. A definition owns a stable key, label, description, point value, optional point-expiry period, active flag and sort order. Issuing a warning snapshots the configured points and calculated expiry into the discipline action, so later definition edits do not silently rewrite historical actions.

Active warning points are calculated from non-revoked warning actions whose time window still contains the current UTC time. Expiry therefore requires no destructive cleanup job.

## Discipline actions

`forwext_discipline_actions` is the immutable action history with explicit revocation metadata. Supported action types are:

- `warning` — configured point award with optional expiry;
- `restriction` — posting/content restriction, temporary or permanent;
- `suspension` — temporary authentication suspension; an expiry timestamp is mandatory;
- `ban` — temporary or permanent authentication ban.

Restrictions are normalized in `forwext_discipline_action_restrictions`. Revocation records who removed an active action, when it was removed and a bounded reason; historical rows are not deleted.

## Permission enforcement

Discipline administration is split into explicit permissions:

- `moderation.discipline.view`
- `moderation.warning.issue`
- `moderation.warning.manage`
- `moderation.restriction.manage`
- `moderation.ban.manage`
- `moderation.discipline.revoke`

All moderator operations also require `moderation.access`. The built-in Moderator and Administrator templates receive these capabilities; normal user templates receive explicit deny defaults.

Posting/content restrictions are enforced inside `DatabasePermissionRuleRepository`, not by hiding buttons. For affected create/manage permissions, an active matching restriction contributes a synthetic user-level deny at the same node scope used by the permission request. This prevents a node/group/role allow from bypassing an active restriction and automatically extends to later first-party domains when they use their registered permission keys.

The current mapping covers forum thread/post creation, profile posts/comments, portfolio create/manage-own, marketplace listing create/manage-own and giveaway creation.

## Authentication enforcement

Active `suspension` and `ban` actions are checked by `DatabaseDisciplineAuthenticationAvailability`.

Normal login and authenticated web viewer resolution require both the existing user-status availability and discipline availability. This means an already-issued session cannot continue using ordinary authenticated routes after a ban/suspension is applied.

The predicate is time-based: a temporary action stops blocking authentication immediately after its stored UTC expiry, without requiring a daemon or destructive status-reset job. This preserves the cPanel deployment profile.

`/account/discipline` is intentionally resolved from an otherwise-valid existing session without the discipline availability gate so an affected user can read the action, expiry and appeal reference. It does not restore access to ordinary authenticated application routes.

## Appeal hook

Every appealable action exposes the stable reference `discipline:<action-id>` and dispatches `moderation.discipline.appeal_available` after a successful issue transaction. This is the first-party integration hook for the later Support/Ticket implementation.

09.04 deliberately does not fabricate a support URL or placeholder ticket. The account discipline page exposes the stable reference now; the future support module can consume the typed domain event/reference when its roadmap step is implemented.

## Audit, notifications and concurrency

Issue, definition-change and revoke mutations reuse the existing moderation audit stream and HTTP request-id correlation. User notifications reuse the durable notification subsystem and point to `/account/discipline`.

Discipline rows are append-only for issue history. Revoke uses a row lock and conditional update. A stale/double revoke is surfaced as a conflict rather than overwriting newer moderator state.

Moderator form mutations reuse the existing same-origin moderation request guard. User-provided reasons, usernames and stored labels are escaped by the native PHP rendering layer.

## Deployment

Migration `20260918003000_discipline_system` is additive and idempotent. It creates warning definitions, discipline actions and restriction rows, seeds starter warning definitions, registers the six discipline permissions and seeds built-in permission templates.

No database reset, Node.js, Redis, Docker, Supervisor or long-running worker is required for the minimum cPanel runtime.
