# Forwext design tokens

This package is the first-party TypeScript/React consumption boundary for the canonical Forwext design-token manifest at `resources/design-tokens/forwext-default.json`.

The JSON manifest is also consumed and validated by the native PHP frontend. Node/npm is a development/build concern only; the standard cPanel production runtime does not require it.

Token keys are stable semantic contracts. A frontend may consume the manifest values, or use `designTokenCssVariable()` to address the CSS custom properties emitted by the native compiler.

The package also exports the shared component, background and responsive appearance manifests so the optional modern frontend follows the same validated presentation contracts as native PHP.
