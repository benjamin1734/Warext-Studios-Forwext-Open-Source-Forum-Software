# Appearance Studio guided configuration

Roadmap step 16.08 adds the simple-by-default entry layer for the appearance systems built in 16.01–16.07.

## Scope

The native PHP administration surface is available at `/admin/appearance`. It is intentionally read-only: choosing Basic/Advanced mode, a preset, a device preview or a search query does not mutate live site state.

Persistent appearance changes continue to happen in the revision-aware theme and layout systems:

- `/admin/appearance/themes` for theme/template/language staging, diff, publish and rollback;
- `/admin/appearance/layout` for layout drafts, widget placement, conditions, preview, import/export and publish.

This separation prevents a guided helper from becoming a hidden authorization or publication path.

## Basic and Advanced modes

Basic mode shows the common theme and layout tasks. Advanced mode adds revision/assets, route/audience/device conditions, import/export and publish-oriented entries.

Changing the mode only changes disclosure. It never changes values.

Every item declares its backend permission. The guide itself requires `appearance.manage`. Entries requiring `appearance.advanced` remain visibly locked when the actor lacks that permission instead of becoming clickable actions.

## Presets

The built-in presets are:

- **Dengeli** — safe default;
- **Kompakt** — denser forum/admin presentation;
- **Vitrin** — more spacious discovery/portfolio presentation.

Each preset is a typed, fully validated preview profile with an explicit list of values it changes. Preview CSS variables are a fixed allowlist with value-level validation; arbitrary CSS cannot enter the preview through query parameters.

Selecting a preset never publishes or overwrites unrelated settings. It changes the isolated preview and the setup-assistant recommendation only. The administrator stages the corresponding durable change in the actual theme/layout editors, where revision, permission and audit rules already apply.

## Search

Search is server-side and bounded to 80 UTF-8 bytes. It searches static setting metadata only: labels, descriptions, safe keywords, paths, permission names and recovery hints.

It does not query stored theme source, custom JavaScript, layout payloads, secrets or protected values.

## Reset and recovery

“Varsayılana dön” resets the guided state to:

- Basic mode;
- Dengeli preset;
- desktop preview;
- empty search.

This is deliberately separate from durable rollback. Live theme/layout recovery remains revision based so a helper cannot silently overwrite published state.

## Preview

Desktop/tablet/mobile previews are rendered on the server using only validated preset variables. Preview is isolated from live state and does not:

- publish content;
- send notifications;
- dispatch webhooks;
- change permissions;
- charge money.

The surface uses no JavaScript dependency and keeps the cPanel baseline intact.

## Setup assistant

The task-language assistant links directly to real first-party editors:

1. choose a safe starting preset;
2. stage theme/template/language changes;
3. edit the layout draft;
4. preview/diff and publish or rollback when authorized.

The assistant does not duplicate the theme/layout persistence layers and therefore cannot bypass their backend permissions, CSRF protection or audit events.

## Deployment and data

No migration is required for 16.08 because guide mode, search, preset and preview-device selection are request-scoped and do not create durable configuration. The authoritative persistent state remains the migration-backed 16.06 and 16.07 revision stores.

No Composer, npm, Node.js, Redis, worker, WebSocket, Docker, SSH or Supervisor runtime is introduced.
