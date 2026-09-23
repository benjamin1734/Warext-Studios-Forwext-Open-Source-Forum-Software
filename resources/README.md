# resources

Source resources shipped with Forwext, including native PHP frontend templates, phrases/languages, emails, static source assets and other non-runtime-generated product resources as later roadmap steps define them.

Generated/built public artifacts belong in the build/public output flow; runtime caches belong in `storage/`.

The canonical cross-frontend design-token manifest lives at `resources/design-tokens/forwext-default.json` and is validated by the native PHP token engine before CSS emission.

The default component appearance binding manifest lives at `resources/appearance/forwext-components-default.json`; it maps fixed component properties to validated design-token keys rather than accepting arbitrary CSS.

The background composition manifest at `resources/appearance/forwext-backgrounds-default.json` defines safe solid/gradient/image/pattern behavior and fixed site/header/category/profile scopes; image assets are restricted to same-origin raster paths under `assets/appearance/`.

The responsive contract at `resources/appearance/forwext-responsive-default.json` defines validated mobile/tablet/desktop breakpoints and fixed-target visibility/font/spacing/layout overrides for both official frontends.

Theme phrase dictionaries, safe template sources, custom CSS and custom JavaScript are versioned through the 16.07 theme payload. Compiled PHP templates and published theme assets belong under protected runtime cache, not `resources/`.
