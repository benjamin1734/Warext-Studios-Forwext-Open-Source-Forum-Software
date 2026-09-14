# Forwext Code Quality & Static Analysis Standard

Status: **Normative for the Forwext 1.x codebase**  
Roadmap step: **02.02 — Kod standartları ve static analysis**

## PHP baseline

- Minimum runtime language: **PHP 8.4**.
- Mandatory compatibility targets: **PHP 8.4 and PHP 8.5**.
- New first-party PHP files use `declare(strict_types=1);` as the first executable declaration.
- Project coding style is **PSR-12**, enforced by PHP_CodeSniffer.
- Namespaces follow PSR-4 and the architecture/domain glossary.
- Backend validation, authorization and security checks may never be replaced by static types or frontend checks.

The repository contains a dedicated strict-types scanner because formatting rules and type analysis serve different purposes. A PHP source file without strict types fails the quality gate even if it otherwise passes PSR-12.

## Static analysis

Two analyzers are intentionally used during the development line:

- **PHPStan level `max`** for strict type/data-flow analysis;
- **Psalm error level `1`** for an independent strict analysis pass and its security/type ecosystem.

New code must not introduce blanket ignore/baseline rules merely to make analysis green. A targeted suppression requires a documented reason at the narrowest practical scope.

The project does not accept “the other analyzer catches it” as a reason to ignore a real error.

## PHP tests

PHPUnit 12 is the baseline test runner while Forwext supports PHP 8.4/8.5. Tests use strict types and deterministic fixtures.

Test layers introduced as the platform grows include:

- unit tests for pure domain/value logic;
- integration tests for repository/service/infrastructure boundaries;
- permission/security tests for allow/deny/inherit/numeric/user-override and IDOR/BOLA edges;
- API contract tests;
- migration/install/update tests;
- browser/E2E tests for critical member/ACP workflows;
- module/add-on lifecycle and graceful-degradation tests.

A test must prove behavior, not just execute lines. Network/external-provider tests use controlled fakes/contracts unless an explicit integration test environment is selected.

## TypeScript / JavaScript standard

Node/npm are **development/build tooling only** and are never a requirement for the native PHP frontend on production cPanel hosting.

- ESLint uses the flat configuration format.
- TypeScript ESLint parser/plugin is pinned to a TypeScript range it officially supports; do not blindly upgrade TypeScript beyond the supported parser range.
- TypeScript uses `strict` compiler options through `tsconfig.base.json`.
- `any` is rejected by default in linted first-party TypeScript.
- Type-only imports are made explicit where applicable.
- Prettier is the formatter for supported JS/TS/JSON/frontend files.

ESLint 10 requires the Node versions declared in `package.json`; this is a developer-machine/build requirement only.

## Formatting and file rules

`.editorconfig` is the cross-editor baseline: UTF-8, LF, final newline, spaces, four-space PHP/general indentation and two-space JS/TS/JSON indentation.

Generated code, vendored dependencies and mutable runtime storage are not hand-formatted or analyzed as project source unless their owning tool explicitly validates them.

## Dependency and lockfile rule

Development dependencies are declared in `composer.json` / `package.json` and recorded in `docs/standards/dependency-inventory.json`.

When dependency installation/build automation is introduced, lockfiles are committed and become the reproducible source for CI/release builds. The current quality-contract step does not hand-author fake lockfiles without resolving the real dependency graph.

Production release ZIPs later include reviewed runtime dependencies and built assets; administrators are not required to run Composer/npm on the server.

## Required local/CI gates

Once dependencies are installed in a development/build environment:

```text
composer qa:php
npm run lint
npm run format:check
```

The PHP aggregate gate runs strict-types validation, PSR-12, PHPStan, Psalm and PHPUnit.

CI introduced later must run these gates on both supported PHP branches where applicable. A later roadmap step may split jobs for speed, but it may not silently lower analysis levels.

## Acceptance rule

02.02 is complete when the repository carries executable configuration for PSR-12, strict types, PHPStan max, Psalm level 1, PHPUnit, ESLint, TypeScript strict options and Prettier; the tooling is license/security-inventoried; and an automated architecture test checks the quality-policy contract.

No production migration is required because this step adds development/build policy and tooling only.
