# Drag-drop page/layout builder

Roadmap step 16.06 builds a safe mutable layout layer on top of the 16.05 region/slot/widget registries.

## Document model

A layout document is a versioned list of widget placements. Each placement has an opaque 128-bit placement id, a registered widget key, a registered target slot, deterministic order, enabled state and a bounded condition set.

Conditions can target a route-name pattern, guest/member/all audiences and one or more desktop/tablet/mobile devices. Route patterns use a restricted identifier/wildcard grammar and never become raw regular expressions or CSS selectors.

Moving a widget changes its slot and order. Reordering changes order. Duplicating creates a new placement id. The browser builder keeps bounded undo/redo snapshots while each saved draft becomes an immutable server-side revision.

## Drafts and safe publish

The database stores a layout identity separately from immutable revisions. A layout has independent draft and published revision pointers.

Publishing uses optimistic concurrency: the caller must provide the exact draft revision id it previewed. The publish update succeeds only while that revision is still the current draft. A stale tab therefore cannot silently overwrite a newer draft.

Every draft is revalidated against the current registered slot/widget catalogs before saving and again before publishing. Removed or unknown extension keys fail closed.

## Import / export

Exports use a versioned forwext-layout-builder JSON envelope and are capped to the validated document model. Imports are limited to 1 MiB, must use the expected layout key/version and are parsed back through the same placement/condition validators.

No PHP, JavaScript, HTML, CSS selectors, URLs or executable template fragments are imported by this format.

## Permissions and audit

Viewing/exporting/saving a draft requires appearance.manage. Publishing and importing additionally require appearance.advanced.

Save, import and publish mutations are recorded through the central administration audit stream and run inside the audit/database mutation transaction.

## Persistence

forwext_ui_layouts stores draft/published pointers and actor timestamps. forwext_ui_layout_revisions stores immutable versioned JSON with a SHA-256 checksum and source (editor or import).

The tables are created by the normal core migration engine and therefore participate in full installation and update ZIP migrations.

## Deployment

## Admin builder surface

The first-party admin surface is available at /admin/appearance/layout. It uses the normal authentication resolver, backend appearance permissions and CSRF middleware.

The browser editor supports drag/drop between registered slots, deterministic reorder on save, duplicate/remove controls, a 50-snapshot undo/redo history, route/audience/device conditions and desktop/tablet/mobile preview widths. Placement ids are generated with Web Crypto rather than Math.random.

Import and publish are separate server mutations, so client-side preview/history cannot bypass backend validation or authorization.

## Deployment

The model and editor are native PHP/JavaScript/MySQL/MariaDB. It adds no mandatory Redis, worker, WebSocket, Node.js, npm, Docker, SSH or Supervisor dependency.
