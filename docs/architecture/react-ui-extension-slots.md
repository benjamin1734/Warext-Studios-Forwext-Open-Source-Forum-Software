# React UI and extension slots (19.05)

Forwext provides an optional `@forwext/react-ui` package for modern React/Next.js consumers. It does not replace or become a dependency of the native PHP frontend.

## Shared design tokens

`tools/generate-react-design-token-contract.php` reads the authoritative PHP `DesignTokenCatalog` and generates the React token key/CSS-variable contract.

React primitives reference the same `--forwext-*` variables used by the native theme pipeline. Theme values are not duplicated in TypeScript.

CI rejects a stale generated React token contract.

## Accessible primitives

The package includes semantic primitives for buttons, alert/status feedback, surfaces and visually-hidden content.

Accessibility behavior includes:

- native button semantics and disabled/loading state;
- keyboard-visible focus;
- polite/assertive live regions for status/error feedback;
- visually-hidden loading text rather than visual-only state.

The CSS is a normal package asset and can be bundled or linked by the modern frontend; components do not inject runtime CSS.

## Permission-aware presentation

`PermissionProvider`, `useCan()` and `<Can>` consume a server-provided permission snapshot.

Permission keys use the same validated key shape as the PHP permission domain.

These helpers control presentation only. A hidden/visible React control never grants authorization. API and PHP application services continue to enforce permissions server-side.

## Add-on React slots

The canonical manifest types mirror `AddonUiReactManifestExporter`, which is built from the enabled add-on UI registry.

`AddonReactSlotRegistry` indexes add-on widget declarations from that enabled manifest. A renderer registration is accepted only when:

- the add-on id exists in the enabled manifest;
- the manifest namespace is the canonical lowercase namespace derived from that add-on id;
- the widget key is declared by that add-on and belongs to its namespace;
- the target slot/key/order are valid;
- the widget renderer has not already been registered.

`<AddonSlot>` renders registered widgets for a target slot in server-declared order. Optional required-permission lists provide UI filtering while server authorization remains authoritative.

The registry accepts statically bundled React component references. It does not use `eval`, remote component URLs or core source patches.

## Packaging and deployment

The package is ESM with React as a peer dependency. CI typechecks/builds the package, SSR-renders representative accessibility/permission/slot behavior and validates its npm package surface.

React/npm/Node remain optional modern-frontend tooling. Standard cPanel users can continue to run only the native PHP frontend.
