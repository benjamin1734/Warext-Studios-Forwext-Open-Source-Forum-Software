# Forwext Repository Layout

Status: **Normative starting layout**  
Roadmap step: **02.01 — Monorepo/klasör yapısı**

Forwext uses a single source repository with explicit product/runtime/ecosystem boundaries. The layout is intentionally understandable to contributors and supports cPanel releases without preventing advanced server or frontend tooling.

## Required top-level directories

| Directory | Ownership / purpose |
| --- | --- |
| `app/` | application composition and delivery/use-case adapters |
| `config/` | safe defaults plus update-protected generated site configuration/key location |
| `core/` | mandatory non-uninstallable platform/runtime foundation |
| `modules/` | first-party integrated Forwext modules |
| `addons/` | third-party add-on packages/installation area |
| `themes/` | themes and theme-owned source presentation resources |
| `resources/` | templates, phrases, emails and product source resources |
| `database/` | versioned migrations/install data/schema evolution resources |
| `storage/` | mutable protected runtime/site-specific state |
| `public/` | preferred HTTP document root only |
| `frontend/` | optional official React/Next.js frontend source/build workspace |
| `packages/` | reusable SDK/UI/developer ecosystem packages |
| `tests/` | central automated test suites/infrastructure |
| `docs/` | architecture, standards, legal, operations and product docs |
| `tools/` | development/release/validation/scaffolding tooling |

## Dependency direction

Primary dependency direction:

```text
app ───────► core
 │           ▲
 ├────────► modules ─────► core
 │
 └─ coordinates enabled product capabilities

addons ─────► documented public core/module extension contracts

themes/resources/frontend/packages
        └── consume documented UI/API/design contracts
```

Hard rules:

1. `core/` cannot require a specific `modules/` or `addons/` implementation.
2. First-party modules use shared core services and explicit module dependencies.
3. Third-party add-ons do not patch core files as their supported extension mechanism.
4. `public/` never becomes a dumping ground for PHP source, secrets or private runtime data.
5. `storage/` is mutable and update-protected; source-of-truth application code does not live there.
6. `config/generated.php` and `config/secret.key` are site-specific/update-protected and are not committed.
7. `frontend/` is optional for deployment; native PHP remains first-class.
8. `packages/` exposes deliberate consumable contracts, not accidental imports from server internals.
9. Database schema ownership evolves through versioned migrations.

## Source versus generated state

Source-controlled:

- application/core/module/add-on/theme source intended for the repository;
- safe configuration defaults/examples;
- migration definitions;
- templates/phrases/source assets;
- tests and tools;
- policy/architecture docs.

Not source-controlled by default:

- generated site configuration and master key;
- mutable runtime cache/log/session/queue/temp state;
- local secrets/environment files;
- dependency working directories such as `vendor/` or `node_modules/`;
- frontend/tool build caches.

Release ZIP generation is different from Git source tracking: production artifacts may vendor reviewed dependencies and built assets without requiring those working directories to be committed to Git.

## Security boundary

`public/` is the preferred web root. Where shared hosting cannot configure the document root, compatibility bootstrapping must explicitly deny access to application source, config/secrets, `storage/`, database resources, tests, tools and backups.

Directory layout alone is not access control; web-server/runtime enforcement is implemented in the HTTP/deployment steps.

## Extension boundary

First-party modules and third-party add-ons are kept physically and semantically distinct so the module manager, migration engine, permissions, settings, routes, jobs and UI extension registries can reason about ownership later.

A feature's directory never grants permission to execute it. Authorization remains in the shared permission engine/backend.

## Acceptance status

02.01 established the required initial boundaries; subsequent architecture steps may add explicit top-level operational directories such as `config/` when their runtime contract is implemented. No runtime bootstrap code is fabricated before its roadmap step.
