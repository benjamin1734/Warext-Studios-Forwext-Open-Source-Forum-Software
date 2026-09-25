# First-party module manager

Roadmap step 17.03 makes Forwext's first-party feature systems manageable as one lifecycle graph without turning them into unrelated applications.

## Lifecycle

Each registered module has one of three runtime states:

- `enabled`: routes and ambient runtime integrations are active;
- `disabled`: code and retained data remain installed, but the module's mapped routes return a temporary unavailable response and its ambient middleware is skipped;
- `uninstalled`: mapped routes return not found and persistent data follows the selected uninstall policy.

Install returns an uninstalled module to the safe `disabled` state. The administrator must explicitly enable it afterwards. Reinstall resets the module data marker to `retained` so an active module never remains labelled as purged.

Lifecycle changes require both ACP access and the dedicated `module.manage` permission. Mutations are POST-only behind the module-manager CSRF middleware and are recorded in the Administration audit stream.

## Dependency and conflict graph

The typed registry validates all dependency/conflict references at bootstrap and rejects dependency cycles.

Enable requires all declared dependencies to be enabled and all conflicts to be inactive.

Disable requires enabled dependents to be disabled first.

Uninstall requires every dependent to be uninstalled first. When the administrator selects **delete data**, dependent module data must also already be purged. This prevents retained child data and foreign keys from producing a partial parent purge.

The ACP displays dependencies, dependents and conflicts with current lifecycle state; backend validation remains authoritative.

## Keep data / delete data

Uninstall always requires the administrator to type the exact module key.

**Keep data** changes lifecycle state to uninstalled while preserving the module's data and scoped settings.

**Delete data** performs the database purge in the same transaction that records the lifecycle audit event. Static purge table names are validated by the typed module definition and cannot come from request input.

Storage paths are collected before database rows are removed and are written to a persistent purge queue. Storage deletion runs outside the database mutation because filesystem/object-storage deletion is not transactional. Failed objects remain queued with bounded error metadata and can be retried from ACP.

The audit snapshot records the actual post-purge data state and pending storage count, including the case where a module declares storage queries but currently has zero objects.

## Scoped settings

Module settings declare their type, safe default, supported scopes and optional integer/string constraints.

Supported scopes are:

1. post;
2. thread;
3. forum;
4. group;
5. global.

The effective lookup order is post → thread → forum → group → global → typed safe default.

The ACP supports global, forum, group, thread and post targeting. Forum/group targets and recent thread/post targets are discoverable through bounded selectors while an exact valid ID may also be entered.

Reset removes the selected scope override rather than writing the default value. That restores normal fallback behavior.

All scope targets are existence-checked before mutation. Stored values are typed JSON and are validated against the setting definition before persistence.

## Runtime enforcement

`FirstPartyModuleRouteMiddleware` maps first-party route-name prefixes to module lifecycle state.

`FirstPartyModuleConditionalMiddleware` gates ambient integrations that otherwise execute on unrelated routes. The native web application currently applies it to:

- Easter Egg rendering;
- Advertising/Notice rendering;
- Analytics request collection.

The lifecycle route gate is placed before those ambient integrations, so disabled/uninstalled module routes fail closed before unrelated first-party middleware can execute.

Legacy Easter Egg decoration is covered by the same module-state gate.

## Deployment

The module manager uses the existing native PHP stack, permission engine, audit stream, storage driver and MySQL/MariaDB migration engine.

It does not require Composer, npm, Node.js, Redis, workers, WebSocket, Docker, SSH or Supervisor at runtime on the minimum cPanel profile.
