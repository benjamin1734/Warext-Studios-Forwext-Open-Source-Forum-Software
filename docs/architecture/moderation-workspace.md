# Moderation Workspace

Forwext 09.01 establishes one internal moderation workspace without duplicating the existing moderation, permission or authentication systems.

## Scope

The workspace presents the plan-defined moderation areas in one internal surface:

- reports
- approval / pending content
- warnings
- bans
- moderator tasks

09.01 is the composition and workspace foundation. The detailed report lifecycle belongs to 09.02, the cross-domain approval queue belongs to 09.03, and warning/discipline/ban semantics belong to 09.04. Until those domains exist, their workspace sections remain real empty sections; Forwext does not fabricate records, routes or counts.

## Provider model

`ModerationWorkspaceSource` is the read-model extension point. Each real moderation domain contributes a section, count and bounded list of `ModerationWorkspaceItem` records. `ModerationWorkspaceService` initializes every plan-defined section, merges registered sources and applies a per-section result bound.

This keeps future report, portfolio, marketplace and discipline work inside the same workspace rather than creating parallel moderator dashboards.

## Authorization boundary

The workspace requires the existing global `moderation.access` permission before any source is read. Task mutations additionally require `moderation.manage` inside the domain service.

The initial forum approval source derives forum nodes from `ForumNodeRepository`; it never accepts a client-provided node allowlist. For every resolvable forum node it requires both `forum.view` and the corresponding node-scoped moderation permission (`forum.thread.moderate` or `forum.post.moderate`) before that node may contribute pending rows.

Pending queries expose only identifiers, titles/positions and timestamps needed by the workspace. Post bodies are not selected into the dashboard read model.

## Current real sources

### Forum pending content

Existing thread/post `moderation_state = pending` data is exposed in the Approval section. Deleted content and merged/deleted parent threads are excluded. Thread and post moderation scopes are evaluated separately.

This does not replace `ContentModerationService`; approval/action mutations continue to belong to the established moderation service and later 09.03 queue integration.

### Moderator tasks

09.01 adds persistent internal team tasks with:

- title and bounded description
- low/normal/high/urgent priority
- open/in-progress/done status
- creator and optional assignee
- optional UTC due date
- created/updated timestamps

Task creation and status changes use `moderation.manage`. They are written transactionally together with the existing moderation audit store using `workspace.task_create` and `workspace.task_status` audit actions. This reuses today's audit infrastructure but does not claim the broader central-audit work of 09.06 is complete.

## Web surface and request safety

`/moderation` is an authenticated internal route. Responses are `no-store` and `noindex, nofollow`.

Task mutations require a same-origin JavaScript request carrying `X-Forwext-Moderation: 1`. If an Origin header is present it must match the configured canonical origin, and cross-site `Sec-Fetch-Site` values are rejected. The actor is always resolved from the existing authenticated session; user or role identity is never accepted from form input.

All rendered workspace titles, summaries, statuses and identifiers are HTML escaped.

## Persistence and deployment

Migration `20260918001000_moderation_workspace_tasks` creates `forwext_moderation_tasks` with creator/assignee foreign keys and bounded lookup indexes. It is idempotent and data-preserving; normal upgrades do not reset existing forum/user data.

The feature remains native PHP/MySQL and adds no mandatory Node, Redis, Docker, Supervisor or persistent-worker dependency to the minimum cPanel runtime.
