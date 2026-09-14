# core

Mandatory Forwext runtime/platform foundation.

`core/` contains non-uninstallable primitives and shared contracts such as bootstrap/kernel, DI, HTTP/routing/security, configuration, persistence foundations, migrations, identity/auth/permissions foundations, events and extension infrastructure as roadmap steps implement them.

Dependency rule: **core must not depend on a specific first-party module or third-party add-on.** Modules/add-ons extend or consume public core contracts, never the reverse.
