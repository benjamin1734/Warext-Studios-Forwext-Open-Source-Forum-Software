# addons

Third-party add-on installation/source area for the Forwext extension platform.

Official first-party modules do **not** live here. Add-ons must use documented public extension APIs and manifests instead of editing `core/` or `app/` files.

Package layout is `addons/Vendor/AddOn/addon.json`. The canonical ID inside the manifest must match that directory path. Symlinked package content and packages escaping the configured add-on root are rejected.

The 18.01 manifest contract, version constraints, dependency/conflict semantics, lifecycle states and data-retention rules are documented in `docs/architecture/addon-manifest-package-lifecycle.md`.

The final installer/updater must treat site-installed add-ons as managed extension data and must not blindly delete them during core updates.
