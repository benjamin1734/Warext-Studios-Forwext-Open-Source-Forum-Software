# Role and user-group model

Status: **Implemented for roadmap 05.01**

Forwext keeps **groups** and **roles** separate on purpose. A group is membership classification: every persisted user authorization context has one primary group and can have zero or more secondary groups. A role is an independently assignable functional/presentation identity. Permission evaluation is intentionally not implemented here; roadmap 05.02 consumes these subjects through the shared permission engine.

## Invariants

- A user has at most one persisted primary-group row because `forwext_user_primary_groups.user_id` is the primary key. Application flows that establish an authorization context must assign a primary group before permission evaluation.
- Secondary groups are unique per user/group and cannot duplicate the primary group in the domain model.
- Role assignments are unique per user/role.
- `RoleKind::Staff` identifies staff roles without forcing staff into a separate user identity model.
- `RoleKind::System` marks platform-owned roles and requires destructive-management protection.
- System user groups are explicit so ACP and installers can protect platform-owned defaults without confusing them with roles.
- Stable lowercase keys are unique independently of translated display names.

## Persistence

The 05.01 migration adds five normalized tables: group catalog, role catalog, one-primary-group membership, secondary-group memberships and direct role assignments. Foreign keys fail closed on unknown users/groups/roles; group/role deletion is restricted while assignments exist. User deletion cascades memberships/assignments.

Splitting primary and secondary memberships into separate tables is deliberate: MySQL/MariaDB do not provide portable partial unique indexes, so this design enforces one primary group per user structurally without accidentally limiting secondary membership to one row.

## Security and permission boundary

This model does not treat hidden UI as authorization. It only defines trusted authorization subjects and membership state. Effective allow/deny/inherit, numeric limits, per-user overrides and node/forum scope belong to 05.02 and must read these persisted subjects server-side.

No new runtime dependency, worker, Redis service or Node/Composer requirement is introduced; the migration remains compatible with the minimum cPanel deployment profile.
