# Theme, template, language and revision system

Roadmap step 16.07 creates the versioned theme domain used by the native PHP frontend and later Appearance Studio surfaces.

## Base and child themes

A theme has a stable key, display name and optional parent theme. The inheritance chain is bounded to 16 levels and cycles are rejected.

Child revisions store only their own overrides. When a child is published, its parent chain is resolved from published revisions and merged parent-to-child. Unpublished parent staging changes therefore never leak into a child publication.

## Payload

A versioned theme payload contains:

- named template sources;
- locale-keyed phrase dictionaries;
- custom CSS;
- custom JavaScript.

Template and phrase keys use bounded namespaced identifiers. Language keys use validated locale identifiers. Payload size/count limits prevent unbounded ACP imports or database payload growth.

Phrase lookup supports locale-specific values with a Turkish fallback.

## Compile-to-PHP templates

Theme templates use a deliberately small safe syntax: literal text plus escaped `{{ variable }}` placeholders.

`ThemeTemplateCompiler` converts that syntax to a PHP file returning a closure. Literal source is emitted through PHP string literals and every variable is passed through `htmlspecialchars`. No theme template source is evaluated as PHP and the compiler does not use `eval`.

Compiled templates are stored under the protected `storage/` tree by revision. Each generated file has a SHA-256 checksum in an atomic manifest. The runtime rejects missing, symlinked or checksum-mismatched compiled templates before include.

## Revisions, diff, staging and rollback

Theme edits create immutable revisions. A theme has independent staging and published revision pointers.

- staging is the editable preview target;
- publish requires the exact current staging revision;
- rollback repoints staging to an older revision belonging to the same theme;
- diff compares template, phrase, CSS and JavaScript paths between two revisions.

Publishing compiles the effective inherited template payload before the database pointer is changed. A compile failure therefore cannot publish a broken revision.

## Custom CSS and JavaScript

Custom CSS/JS are versioned with the theme. Non-empty custom CSS or JS requires the advanced appearance permission.

Custom CSS is bounded, rejects `@import`, and rejects external/executable/data URLs. Custom JavaScript is bounded and rejects an HTML closing-script sequence so it cannot break a future script container.

The actual public asset delivery endpoints are kept same-origin and revision-addressed.

## Permissions and audit

Reading/staging themes requires `appearance.manage`.

Publishing, rollback and advanced custom CSS/JavaScript require `appearance.advanced` in addition to management access.

Stage, publish and rollback mutations use the central Administration audit stream.

## Deployment

Theme state uses MySQL/MariaDB migrations. Template compilation writes protected PHP cache artifacts under `storage/`; normal cPanel hosting requires no Node.js, npm, Redis, worker, Docker, SSH or Supervisor runtime.
