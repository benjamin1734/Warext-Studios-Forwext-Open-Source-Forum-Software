# Global and node permission engine

Status: **Implemented for roadmap 05.02**

Forwext resolves permissions server-side from the 05.01 user/group/role assignment model. The engine supports global rules, node/forum-scoped rules, `allow`, `deny`, `inherit`, numeric limits and direct per-user overrides. Unknown permissions and repository failures fail closed.

## Deterministic precedence

Resolution order is intentionally fixed and does not use role display priority:

1. node-scoped direct user rule
2. global direct user rule
3. node-scoped group/role rules
4. global group/role rules
5. implicit deny

This makes a deliberate per-user override stronger than membership policy while still allowing a node-specific user override to supersede a global user override. Between group and role rules at the same tier, `deny` wins over `allow`; `inherit` contributes no decision and falls through to the next tier. Role/banner priority is presentation metadata and cannot silently change authorization.

## Numeric permissions

A numeric permission uses the same precedence. An `allow` rule must carry a non-negative numeric limit. When several group/role allows apply in one tier, the smallest value wins so the combined membership policy is the most restrictive. A `deny` remains decisive. Invalid/malformed rules fail closed rather than being coerced into access.

## Persistence

`forwext_permissions` stores typed permission definitions (`flag` or `numeric`). `forwext_permission_global_rules` stores global user/group/role rules. `forwext_permission_node_rules` stores node-specific rules using a generic `node_id`; this is deliberate because the forum/node domain is introduced later and 05.02 must not create a premature foreign-key dependency on a table that does not yet exist.

Subject lookup is parameterized with `CompiledQuery`; user, group, role, permission and node identifiers are never interpolated as SQL values. Polymorphic subject IDs are validated through the domain model when hydrated.

## Decision trace and analyzer compatibility

Every evaluated decisive/inherited rule can be returned in `PermissionDecision::trace()`. The trace contains the deterministic tier, original typed rule and outcome. This is machine-oriented infrastructure for roadmap 05.04; the human-readable explanation/visualization UX belongs to that later sub-step.

## Security and deployment

- Unknown permission keys: deny.
- Rule repository/database failure: deny without leaking exception details.
- Invalid rule/value combination: deny.
- Backend resolver is authoritative; hiding a frontend control is not authorization.
- No Redis, worker, Node.js, Composer-at-runtime or new PHP extension is required.
- The repository abstraction remains cache/provider friendly for advanced deployments without changing permission semantics.

Roadmap 05.03 may seed and apply permission templates on top of these definitions/rules. Roadmap 05.06 can migrate first-party system-specific permission bridges onto this shared engine without changing the precedence contract.
