# Design token engine

Roadmap step 16.01 establishes one canonical presentation-token contract for Forwext.

## Canonical manifest

`resources/design-tokens/forwext-default.json` is the shipped source manifest. It carries versioned tokens in these required categories: color, typography, spacing, radius, border, shadow, motion and semantic.

Raw tokens contain validated CSS-safe primitive values. Semantic tokens are aliases and must reference another registered token. Unknown references, duplicate keys and reference cycles fail closed.

## Native PHP frontend

`DesignTokenCatalog` validates the canonical manifest and `DesignTokenCssCompiler` emits deterministic `--forwext-*` CSS custom properties. The native PHP frontend consumes the compiler directly.

The existing short variables such as `--bg`, `--panel`, `--text` and `--accent` remain compatibility aliases for now, but resolve to the new semantic tokens. This keeps current forum surfaces visually stable while later Appearance Studio steps migrate components incrementally.

Motion-duration tokens are forced to `0ms` inside `prefers-reduced-motion: reduce`.

## React / TypeScript boundary

`packages/design-tokens/index.ts` imports the same JSON manifest and exposes the stable CSS-variable naming contract for the optional first-party modern frontend. This is source/build tooling only and does not add Node.js or npm to the normal cPanel production runtime.

## Security

The PHP loader rejects unknown categories, malformed/duplicate keys, missing references, reference cycles, raw semantic values, CSS statement delimiters/control characters, `url()`, `expression()`, `var()`, `@import` and category-incompatible primitives.

The current step has no browser/user-controlled override endpoint and therefore needs no new permission or audit event. Future ACP/theme overrides must pass the same validation boundary and their own backend authorization/audit requirements.

## Deployment

No database migration is required for 16.01. The manifest is a shipped immutable application resource and is included in normal full/update packages. The implementation requires no Redis, worker, WebSocket, Docker, Node.js, npm, SSH or Supervisor service at runtime.
