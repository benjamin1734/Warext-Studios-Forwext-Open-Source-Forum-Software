# Layout regions, UI slots and widgets

Roadmap step 16.05 introduces the first-party layout extension contract shared by core, modules and add-ons.

## Regions and named slots

The canonical regions are header, main, sidebar, footer and page.

Core named slots are:

- page.before / page.after;
- header.before / header.after;
- main.before / main.after;
- sidebar.primary;
- footer.before / footer.after.

Slots are typed, ordered and registered in `UiSlotRegistry`. Module/add-on slot extensions must use their owner namespace, preventing unrelated extensions from silently claiming each other's names.

## Widget registry

`WidgetRegistry` stores typed widgets with:

- stable namespaced key;
- target slot;
- deterministic order;
- bounded cache TTL;
- core/module/add-on ownership metadata.

Core widget keys use the `core.*` namespace. Module/add-on registrations must stay inside their owner namespace. Duplicate keys and unknown slots fail closed.

`WidgetContributor` and `UiSlotContributor` are explicit extension points; modules/add-ons do not patch native templates to participate.

## Rendering and cache

`WidgetRenderService` renders all widgets for a named slot in deterministic order.

Cache is optional and reuses the existing `CacheStore` abstraction. Standard cPanel installs may use the existing file/database cache profiles; Redis remains optional. Cache keys contain only a SHA-256 fingerprint of bounded widget context, not raw page titles or user data. Widget/slot tags support targeted invalidation.

A widget with TTL 0 bypasses cache. Cached widgets are bounded to at most one day by registry validation.

## Native shell

The native PHP shell renders every core named slot. The sidebar region is emitted only when a sidebar widget produces content, preserving the existing single-column layout by default.

A core footer widget proves the end-to-end registry/render path and provides the Forwext / Warext Studios product attribution. Future modules and add-ons can contribute widgets through the same registry contract.

## Security and permissions

Widgets are presentation extensions and are never an authorization boundary. Hiding or omitting a widget does not grant or revoke access. Any widget that reads protected data or performs an action must call the owning backend permission/service layer exactly as a normal route would.

Widget HTML is trusted extension output, not a raw end-user HTML field. Add-on/module code remains subject to the project clean-room, dependency and security policies.

## Deployment

No database migration is required for the registry itself. The native shell works without a cache service and therefore introduces no Composer/npm/Node/Redis/worker/WebSocket/Docker/SSH/Supervisor requirement for standard cPanel deployment.
