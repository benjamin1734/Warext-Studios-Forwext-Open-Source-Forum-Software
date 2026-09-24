# Users, roles, forums and moderation ACP

Roadmap step 17.02 extends the central Administration Control Panel with native PHP management surfaces for users, access policy, forums, content and moderation.

## ACP surfaces

- `/admin/users` — user directory, account history and direct group/role assignments.
- `/admin/access` — groups, roles, role banner/appearance and permission analyzer.
- `/admin/forums` — category/forum/page/link node hierarchy and forum behavior settings.
- `/admin/content` — thread/post health summary and links into the first-party Content Manager, approval queue and freshness system.
- `/admin/moderation` — reports, moderation tasks, warnings/bans and administration audit entry points.

All five surfaces are native PHP and require the existing `acp.manage` backend permission.

## Users and account state

The user ACP searches the real `forwext_users` table and uses the existing User aggregate/history repository for selected-account detail.

Direct primary/secondary group and role assignment changes are written transactionally and generate Administration audit events.

A manager cannot rewrite their own access assignment or account state from this surface. This prevents accidental ACP lockout.

Suspension and ban are deliberately not implemented as direct user-table writes. Those actions remain in the existing DisciplineService workflow because it owns moderation permissions, moderation audit, authentication availability, notification and revoke behavior. Accounts already in suspended/banned state cannot be reactivated from the generic ACP status form.

## Groups, roles and banners

Custom groups and custom/staff roles can be created and existing groups/roles can be updated.

System group keys and protected role key/kind semantics remain immutable. System roles cannot be created through ACP.

Role appearance reuses the first-party `RoleAppearance` model and repository, including:

- text color;
- gradient;
- icon;
- banner text/color;
- pattern;
- animation;
- mobile/profile/post visibility.

Appearance writes produce Administration audit events.

## Permission analyzer

The ACP analyzer does not implement a second permission algorithm.

It composes the existing:

- `DatabaseUserAccessAssignmentProvider`;
- `DatabasePermissionRuleRepository`;
- `PermissionEngine`;
- `PermissionAnalyzer`.

The result therefore includes the real user/global/node/group/role precedence trace and the same fail-closed behavior used by runtime authorization.

## Forums

The forum ACP creates and updates category, forum, page and link nodes through `DatabaseForumNodeRepository`.

The repository's existing `ForumNodeHierarchy` validation remains authoritative, so parent cycles and structurally invalid hierarchy changes fail instead of being guessed around.

Existing node type cannot be changed through this surface. This avoids silently converting a forum with content into a page/link and losing type-specific semantics.

Destructive node deletion is intentionally not surfaced by 17.02; create/update is safe-by-default while repository-level deletion remains constrained by child/content relationships.

## Content

The content ACP is an overview rather than a second mutation implementation. It shows real thread/post totals, pending moderation and soft-delete counts, then links into the existing first-party systems that own the mutations:

- User Content Manager;
- Approval Queue;
- Thread Freshness.

Content Manager visibility is checked using the real `content_manager.access` permission.

## Moderation, reports, bans and audit

The moderation ACP exposes real queue counts only after the corresponding permissions pass.

It links to the existing Moderation Workspace, Discipline and Core Audit screens. Report/ban/audit mutations therefore stay in their existing services instead of being duplicated in ACP.

Core audit rows are read through `CoreAuditService` with an actor-bound `PermissionGate`.

## Security

17.02 keeps the following boundaries:

- backend `acp.manage` is mandatory for every new management surface;
- POST mutations use the dedicated `admin-community` CSRF context;
- every access/group/role/banner/forum mutation records a core Administration audit event;
- self access/status mutation is denied;
- ban/suspension bypass through generic account status mutation is denied;
- identifiers are validated and referenced group/role objects must exist;
- permission analysis is fail-closed because it uses the production permission engine;
- no JavaScript or new runtime service is required.

## Deployment

No new database schema is required for 17.02. The implementation uses the user/group/role/role-appearance/forum/moderation/audit tables created by earlier completed roadmap steps.

The minimum cPanel profile remains PHP 8.4+, MySQL/MariaDB, Apache/LiteSpeed/Nginx with the native PHP frontend. Composer, npm, Node.js, SSH, Redis, Docker and Supervisor remain optional/non-runtime requirements.
