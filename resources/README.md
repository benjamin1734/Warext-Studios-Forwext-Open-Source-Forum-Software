# resources

Source resources shipped with Forwext, including native PHP frontend templates, phrases/languages, emails, static source assets and other non-runtime-generated product resources as later roadmap steps define them.

Generated/built public artifacts belong in the build/public output flow; runtime caches belong in `storage/`.

The canonical cross-frontend design-token manifest lives at `resources/design-tokens/forwext-default.json` and is validated by the native PHP token engine before CSS emission.

The default component appearance binding manifest lives at `resources/appearance/forwext-components-default.json`; it maps fixed component properties to validated design-token keys rather than accepting arbitrary CSS.
