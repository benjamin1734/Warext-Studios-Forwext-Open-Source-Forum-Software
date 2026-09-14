# modules

First-party Warext Studios / Forwext integrated modules.

Examples later include Support, FAQ, Bug Reports, Portfolio, Marketplace, Giveaway, Analytics and other systems defined by the binding roadmap.

Rules:

- modules reuse shared users, permissions, notifications, moderation, audit, search, storage/media, analytics, queue/scheduler, settings and UI infrastructure;
- modules do not create parallel authentication or permission engines;
- module lifecycle/data ownership is registered through the first-party module manager when implemented;
- module-to-module dependencies must be explicit rather than hidden includes.
