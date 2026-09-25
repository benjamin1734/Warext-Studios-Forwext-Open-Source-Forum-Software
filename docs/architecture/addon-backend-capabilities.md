# Add-on backend capabilities (18.03)

Forwext third-party add-ons contribute backend behavior through typed registrations owned by the canonical `Vendor/AddOn` identity. The backend capability layer extends existing platform registries; it does not create a second router, permission engine, queue, scheduler, search system, notification system or ACP authorization model.

## Capability registration

`AddonBackendRegistration` supports:

- versioned add-on migrations owned by the shared migration engine;
- entity metadata;
- named HTTP routes;
- permission catalog entries;
- typed settings;
- ACP navigation entries;
- queue job handlers;
- cron/scheduled tasks;
- search content sources;
- notification definitions;
- webhook capability metadata;
- content-type metadata.

Capability keys use `addon.<vendor>.<addon>.*` ownership. ACP entries use an `admin.addon.<vendor>.<addon>.*` key and a canonical `/admin/addons/<vendor>/<addon>` path. Registration metadata never grants backend access by itself.

## Persistence and migrations

`AddonBackendProvisioner` executes add-on migrations through the existing `MigrationEngine` and synchronizes permission/setting catalogs.

The core migration `20260925122500_addon_backend_capabilities` adds typed setting-definition and setting-value tables. The migration is additive and idempotent. Existing installations advance through normal migration history; no database reset is used.

Permission definitions reuse `forwext_permissions`. Existing permission or setting value types cannot silently change during synchronization.

## Settings, permission and audit boundaries

`AddonSettingManager` requires both `acp.access` and `addon.manage` for mutations. Save/reset operations run through the Administration audit recorder. The database setting store performs persistence only and does not become an alternate authorization layer.

Reading an effective setting is separate from ACP mutation authorization so runtime application code can consume its own configuration without fabricating an administrator session.

## Runtime registries and atomic preflight

`AddonBackendRuntimeIntegrator` contributes routes, jobs, scheduled tasks, search sources and notification definitions to the existing core registries. Entity, webhook and content-type metadata is stored in `AddonBackendMetadataRegistry`.

Before any live registry is changed, the integrator clones the destination registries and performs the complete registration against the probes. Duplicate routes, job types, scheduler names, search types, notification definitions or metadata therefore fail before a partially-applied live state is created.

A scheduled task must reference a queue job handler registered by the same add-on registration. Broken cron/job pairs fail during registration rather than later during scheduler execution.

## Lifecycle activation

`AddonBackendRuntimeActivator` resolves discovered registrations against the persisted add-on lifecycle repository. Only add-ons in the `enabled` state are copied into the active registry and applied. Disabled, uninstalled or unknown add-ons cannot contribute runtime routes/jobs/search/notifications/metadata merely because registration code is available.

The enabled registry can also be reused by ACP composition and later UI/developer-platform layers, keeping lifecycle state consistent across capability consumers.

## ACP boundary

`AdminNavigationRegistry::withExtensions()` accepts add-on-owned ACP items from the enabled backend registry. Navigation remains a discoverability surface only: the real route/service must still enforce its backend permissions.

No add-on is allowed to treat a hidden or visible ACP card as authorization.

## Webhook scope boundary

18.03 registers webhook capability metadata only. Signed payloads, retry/backoff, delivery logs, secret rotation, test delivery and SSRF-safe destinations belong to roadmap 19.03. This prevents an incomplete or unsigned webhook transport from being introduced prematurely.

## Deployment and security

- No `eval`, runtime source patching or arbitrary core-file replacement is introduced.
- Namespace and duplicate validation fail closed.
- Disabled/uninstalled add-ons are excluded from active runtime registries.
- Permission checks remain backend-authoritative.
- Settings mutations are audited.
- Existing cPanel deployment remains PHP 8.4+ with no mandatory Node, Redis, Docker, Supervisor or long-running worker.
