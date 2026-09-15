# Forwext Third-Party Notices

This file is the human-readable attribution inventory for third-party code/assets distributed with Forwext.

## Production/runtime redistribution

### web-auth/webauthn-lib

Forwext uses `web-auth/webauthn-lib` for standards-compliant WebAuthn/passkey ceremony construction and cryptographic verification.

- Reviewed source: `https://github.com/web-auth/webauthn-lib`
- Version constraint: `^5.3.9`
- License: MIT
- Scope: production runtime

The exact packaged version and all transitive Composer runtime dependencies must be locked and re-inventoried during release packaging. A release must not ship an unreviewed dependency graph or omit required third-party license/notice material.

## Development/build tooling

Roadmap step 02.02 declares development-only quality tooling in `composer.json` and `package.json`, including PHPUnit, PHPStan, Psalm, PHP_CodeSniffer, ESLint, typescript-eslint, Prettier and TypeScript. These tools are not application runtime dependencies and are not redistributed in production ZIPs by this step.

Exact reviewed versions, licenses and security sources are tracked in `docs/standards/dependency-inventory.json`.
