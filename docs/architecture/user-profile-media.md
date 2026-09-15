# User profile and profile media architecture

Step **04.06** adds the first persisted member-profile domain to Forwext.

## Data model

A profile belongs to exactly one core user. `forwext_user_profiles` stores the about text, private-storage media references and the visibility policy for the profile, about block, social links and media. Social links and tab preferences are normalized into child tables with foreign-key cascade deletion.

Profile media is **not** written beneath the public document root. Avatar and banner objects use the storage driver with `private` visibility and are served only after the profile visibility policy has approved the viewer.

## Visibility and authorization

The current safe policy deliberately has no synthetic `isStaff` override. Before the shared role/permission engine in roadmap step 05.x exists, only the profile owner can edit profile data or media. Public profiles are guest-readable, members profiles require an authenticated viewer, and private profiles are owner-only. Section visibility is evaluated only after the profile itself is visible.

`ProfileAccessPolicy` is the integration boundary for the later shared permission engine. A future staff/moderation override must be implemented by that policy layer rather than by trusting request parameters.

## Media pipeline

Avatar uploads are limited to 8 MiB and 4096×4096. Banner uploads are limited to 16 MiB and 8192×4096. Only JPEG, PNG and WebP payloads accepted by PHP's image-header parser are stored. SVG is intentionally rejected. Filenames are content-addressed with SHA-256 and the extension is derived from the detected image MIME type.

Replacement writes the new private object first, updates the database reference second and best-effort deletes the old object last. If the database update fails, the newly staged object is removed best-effort. This ordering prevents a successful database update from pointing at an object that was never written.

## Rendering contract

About text, usernames, social URLs/labels and tab labels are untrusted input. Native PHP views must escape them with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. About text is plain text at this stage and must not be rendered as raw HTML.

## cPanel/runtime impact

No worker, daemon, Redis service, Node.js process or image-manipulation extension is required. The default MySQL/PDO database and local private-storage driver remain valid on cPanel. S3-compatible private storage can be substituted through the existing storage abstraction.
