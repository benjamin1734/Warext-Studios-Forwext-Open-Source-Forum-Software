# packages

Shared developer/ecosystem packages that are independently consumable or versioned, such as the future TypeScript SDK, React UI SDK/components and developer tooling libraries.

Packages must depend only on documented contracts appropriate to their target and must not import private server internals as an unofficial API.

`packages/design-tokens/` exposes the shared design-token manifest and CSS-variable naming contract to the optional TypeScript/React frontend without making Node.js a production runtime dependency.
