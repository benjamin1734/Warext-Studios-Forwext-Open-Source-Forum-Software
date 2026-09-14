# addons

Third-party add-on installation/source area for the Forwext extension platform.

Official first-party modules do **not** live here. Add-ons must use documented public extension APIs and manifests instead of editing `core/` or `app/` files.

The final installer/updater must treat site-installed add-ons as managed extension data and must not blindly delete them during core updates.
