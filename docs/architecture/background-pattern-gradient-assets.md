# Background, pattern, gradient and asset system

Roadmap step 16.03 adds a typed compositing layer above the 16.01 design tokens and 16.02 component appearance contracts.

## Supported background modes

The domain supports:

- solid backgrounds;
- two-to-four-stop token-based gradients;
- safe same-origin raster image assets;
- built-in dots, grid, diagonal and checker patterns.

Each background also supports bounded opacity, scale, rotation and CSS background blend modes. Gradient definitions may opt into animated background positioning with a bounded duration.

## Scopes

Backgrounds can target:

- the whole site;
- the header;
- one forum/category node by its opaque 128-bit id;
- one user profile by its opaque 128-bit user id.

Site/header scopes reject entity ids. Category/profile scopes require one. The compiler emits fixed selectors only; arbitrary selectors are never read from configuration.

The native shell now marks site and header scope surfaces. Profile pages expose the existing opaque profile user id through escaped data attributes. Category selectors are supported by the compiler and are ready for the forum/category rendering surface without inventing a placeholder category UI.

## Asset policy

Image backgrounds use `BackgroundAssetPath`. The path must:

- be site-relative under `assets/appearance/`;
- use AVIF, JPEG, PNG or WebP;
- contain no traversal, backslash, query, fragment, control character or external origin.

SVG is intentionally excluded from this background asset path because future administrator uploads must not gain script-capable image execution through the appearance layer. External URLs are also rejected, preventing this layer from becoming an SSRF/open-redirect/tracking URL surface.

The compiler is base-path aware, so cPanel subfolder installs emit correct same-origin asset URLs.

## Composition

The compiler renders the background as a pointer-events-disabled pseudo-layer. The actual content is kept above it, allowing layer opacity, scale and rotation without applying element opacity/transform to text or controls.

Animated gradients emit deterministic keyframe names and are disabled under `prefers-reduced-motion: reduce`.

## Security, permission and audit

The current step ships immutable defaults and typed parsing/compiler APIs only. There is no request-driven mutation, raw CSS, arbitrary selector or external asset URL input. Therefore no new permission, audit event or database migration is required in 16.03.

Future Appearance Studio write surfaces must authorize mutations on the backend, use the typed definitions here, store only validated values and record revisions/audit before publishing.

## Deployment

The native PHP frontend loads the shipped JSON manifest directly. The optional TypeScript/React package imports the same manifest. No Redis, worker, WebSocket, Docker, Node.js, npm, SSH or Supervisor runtime is introduced.
