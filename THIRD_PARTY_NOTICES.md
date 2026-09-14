# Forwext Third-Party Notices

This file is the human-readable attribution inventory for third-party code/assets distributed with Forwext.

## Production/runtime redistribution

**No third-party runtime/library component is currently approved or vendored for redistribution in a Forwext production release.**

## Development/build tooling

Roadmap step 02.02 declares development-only quality tooling in `composer.json` and `package.json`, including PHPUnit, PHPStan, Psalm, PHP_CodeSniffer, ESLint, typescript-eslint, Prettier and TypeScript. These tools are not application runtime dependencies and are not redistributed in production ZIPs by this step.

Their exact reviewed versions, licenses and security sources are tracked in `docs/standards/dependency-inventory.json`.

When a component becomes redistributed, record its required attribution/license text here and carry it into generated release NOTICE/license output.
