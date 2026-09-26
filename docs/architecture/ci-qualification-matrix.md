# CI and automatic qualification matrix

Roadmap step 20.05 turns the existing Forwext regression suite into explicit release-qualification gates.

## Required gates

- PHP 8.4 and PHP 8.5 run explicit unit/integration, API, native browser/web-surface, module lifecycle, sample add-on, architecture and security contract slices.
- MySQL 8.4 and MariaDB 10.11 each run the complete clean migration chain and the cPanel-first post-install web bootstrap.
- Both database engines also run a real incremental migration upgrade: all but the newest registered migration are applied first, then the complete registry must apply exactly the missing migration and remain idempotent on the next pass.
- Both database engines run a post-install runtime module toggle. Marketplace is disabled in persisted module state and must fail closed with HTTP 503, then re-enabled and required to return a usable route.
- The normal build workflow remains the release-build authority: complete PHPUnit on PHP 8.4/8.5, SDK contract/build, React UI contract/build, official Next.js production build, production PHP 8.4 dependency baseline, cPanel full ZIP, differential update ZIP and archive/checksum integrity.

## Workflow separation

The qualification workflow is intentionally read-only and does not publish releases. Database qualification is also read-only with respect to GitHub contents. Only the existing package workflow has contents write permission and it retains the immutable release rules.

This keeps qualification failures from partially publishing a release while still making each roadmap acceptance dimension visible in GitHub Actions.

## Security and runtime impact

The upgrade smoke creates a temporary sibling CI database with a strictly validated identifier and drops it in a finally block. It does not touch a production database.

The module toggle smoke operates only against the CI installation created immediately beforehand and restores the original module state in a finally block.

No new production dependency or daemon is introduced. Composer, PHPUnit and Node remain development/build tooling; normal cPanel runtime continues to require only the documented PHP/database/web-server profile.
