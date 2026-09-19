# Easter Egg system

Substep 13.06 provides lightweight, centrally managed surprise experiences without coupling hidden behavior to individual page handlers.

## Safety-first runtime

The global Easter Egg switch is seeded OFF. When it is disabled, no definition is rendered even if an individual definition is enabled.

Each definition also has its own enabled flag, optional UTC start/end window, priority, route/path target, optional user-group scope, trigger, message, visual badge label, and CSS-only animation.

The runtime is decorative and fail-open. Easter Egg lookup, group lookup, rendering, or storage errors are caught after the real page response has been produced; the untouched forum response is returned instead of turning an optional surprise into a site-wide 500.

Only successful GET/HEAD HTML responses are decorated. API/JSON/download responses, redirects, errors, POST responses and the Easter Egg management page itself are not decorated.

## Route and page targeting

Router-native Forwext pages can use exact internal route names and/or path patterns. When route name and path are both supplied, both must match.

Path patterns support exact absolute paths or one trailing wildcard for prefix matching, for example /giveaways/*.

A small number of legacy Community/Report/Moderation application-factory HTML routes do not expose the Router route-name attribute. Those responses are still covered by path-pattern matching through the front controller's post-response decorator. Use a path pattern when targeting those surfaces.

## Triggers and visibility

Automatic definitions render whenever their other constraints match.

Query-token definitions use the fixed egg query parameter. The configured token is deliberately treated as a discovery trigger, not an authentication secret: URLs can be copied, logged, bookmarked or shared. Security-sensitive actions must never depend on an Easter Egg token.

An empty group selection means any viewer who can already access the page may see the Easter Egg, including guests. When groups are selected, the authenticated viewer must match at least one primary or secondary user group.

The runtime caps a response at three matching Easter Eggs to avoid accidental UI flooding.

## Presentation

Messages, names and badge labels are HTML-escaped. Animations are CSS-only and do not require weakening the existing script CSP. Pulse, glow and decorative confetti modes obey prefers-reduced-motion.

The badge label in 13.06 is purely presentational. It does not grant or persist a user achievement. Persistent trophies, badges and achievements are owned by roadmap substep 13.07.

## Administration and audit

Native management is available at /admin/easter-eggs and is protected by easteregg.manage plus a dedicated easteregg CSRF purpose.

The management surface provides the global kill-switch, per-definition enablement, priority, date window, route/path targeting, automatic/query trigger, message, animation, visual badge and user-group targeting.

Global toggle and definition create/update actions are written to the central administration audit stream. Audit snapshots store configuration metadata and counts rather than duplicating the surprise message text.

## Schema evolution

Migration 20260919160000_easter_egg_system creates settings, definition and group-scope tables with the safe global OFF default.

Migration 20260919160500_easter_egg_path_nullable makes path_pattern nullable so route-name-only definitions are valid without rewriting the already-applied base migration.
