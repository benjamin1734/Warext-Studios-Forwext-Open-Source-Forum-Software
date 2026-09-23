# Component appearance settings

Roadmap step 16.02 adds a safe component-level appearance contract on top of the 16.01 design-token engine.

## Required component targets

The default manifest covers header, navigation, footer, forum, thread, post, profile, button, input, modal, badge, role banner, editor, table and alert.

Each component binds supported appearance properties to an existing design-token key. It cannot inject selectors, raw property names or arbitrary CSS values.

## Runtime

`ComponentAppearanceRegistry` validates the shipped manifest and requires every roadmap target exactly once. Every referenced token must already exist in `DesignTokenCatalog`.

`ComponentAppearanceCssCompiler` emits only deterministic `--forwext-component-*` custom properties whose values are references to validated `--forwext-*` design tokens.

The native PHP frontend consumes the component variables for existing header/navigation/cards/profile/buttons/inputs/badges/alerts. Component contracts for footer/thread/post/modal/editor/table are ready for their owning surfaces without forcing placeholder UI into pages that do not yet render those components.

Role-banner CSS consumes the same component contract while preserving a fallback for isolated embedding.

## Cross-frontend contract

The optional TypeScript/React package exports the same component appearance JSON manifest alongside the design-token manifest. This remains build-time source and adds no Node/npm production dependency.

## Security and permissions

This step introduces immutable shipped defaults only. There is no request/user-controlled raw CSS endpoint, no persistence mutation and no ACP write path yet, so no new permission, audit event or migration is required.

Future Appearance Studio editors must persist only validated token bindings (or stronger typed values introduced by later roadmap steps), enforce backend authorization and record audit/revision history.

## Deployment

No database migration is required. The resources and PHP compiler ship in normal cPanel full/update packages and require no Redis, worker, WebSocket, Docker, Node.js, npm, SSH or Supervisor runtime.
