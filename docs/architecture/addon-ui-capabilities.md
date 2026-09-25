# Add-on UI capabilities (18.04)

Forwext third-party add-ons extend the native PHP and optional React/Next.js frontends through declarative, owner-namespaced UI capabilities. UI registration is not an authorization boundary: protected actions and protected data still require the existing backend permission/service layer.

## Lifecycle and ownership

Every UI registration belongs to canonical `Vendor/AddOn` identity and uses the matching `addon.<vendor>.<addon>.*` namespace.

`AddonUiRuntimeActivator` admits only persisted `enabled` add-ons. Disabled, uninstalled and unknown add-ons remain absent from active UI registries even if registration code is discoverable.

## Slots, widgets and navigation

Add-ons reuse:

- `UiSlotRegistry`;
- `WidgetRegistry`;
- `NavigationRegistry`.

No parallel router/layout/navigation system is created. Duplicate keys and namespace violations fail closed. Existing core slots can host add-on widgets, and add-ons can declare additional namespaced slots.

Navigation remains discoverability only; visible navigation never substitutes for backend authorization.

## Design tokens

Add-ons may contribute namespaced `DesignTokenDefinition` entries. They are merged through the same `DesignTokenCatalog` validation used by the core appearance system, including category-specific safe CSS value rules and reference-cycle checks.

## Templates

`AddonUiTemplateDefinition` and `AddonUiTemplateRegistry` reuse `ThemeTemplateCompiler`.

Templates support the same deliberately small escaped `{{ variable }}` syntax. Add-on template source is never evaluated as PHP and no `eval` path is introduced.

## Editor extensions

`EditorToolbarExtension` adds declarative toolbar buttons to selected `thread` and/or `post` surfaces.

The native rich editor renders these contributions as normal escaped button metadata and reuses the existing wrap-selection behavior. This means a toolbar extension does not need inline JavaScript. Richer add-on behavior can use separately registered external assets while final content rendering remains server-side and safe.

## Compiled CSS/JavaScript assets

`AddonUiAssetDefinition` accepts bounded UTF-8 CSS or JavaScript:

- CSS rejects `@import` and external/executable/data URL references;
- JavaScript rejects an HTML closing-script sequence;
- both are immutable SHA-256 addressed.

`AddonUiAssetCompiler` atomically writes assets to a hash-derived path under `public/addon-assets/`. Paths contain only hashes and trusted extensions, not user-controlled traversal segments.

`AddonUiAssetManifest` emits same-origin stylesheet/script tags with SHA-256 Subresource Integrity. The existing CSP can therefore retain `script-src 'self'`; 18.04 does not add remote script hosts or inline-script allowances.

## Native runtime composition

`AddonUiRuntimeComposer` builds one runtime composition from the already-enabled registry:

- slots;
- widgets;
- navigation;
- design tokens;
- editor extensions;
- templates;
- compiled assets.

This gives the native PHP frontend one deterministic extension graph instead of independent per-feature loading rules.

## React/Next.js contract

`AddonUiReactManifestExporter` exposes a schema-versioned data-only manifest. `frontend/extension-contract.ts` defines the matching first-party TypeScript contract.

The manifest may expose:

- stable add-on id and namespace;
- slot/widget metadata;
- navigation metadata;
- editor extension metadata;
- template keys;
- design-token keys/categories;
- immutable asset URL + integrity metadata.

It deliberately does not expose PHP classes, raw template source, raw CSS/JavaScript content, secrets, stored settings or permission grants.

## Security and deployment

- Backend permissions remain authoritative.
- UI visibility never grants access.
- Inactive add-ons cannot contribute UI.
- Templates are escaped and non-evaluable.
- Asset names are hash-derived; writes are atomic and symlink-protected.
- CSS cannot import remote/executable resources through this contract.
- Same-origin assets preserve the existing CSP.
- Standard cPanel runtime remains PHP 8.4+ with no mandatory Node/npm/Redis/Docker/Supervisor/SSH requirement.
