# User profile and profile media architecture

Step **04.06** adds the first persisted member-profile domain and native PHP profile surface to Forwext.

## Data model

A profile belongs to exactly one core user. `forwext_user_profiles` stores the about text, private-storage media references and the visibility policy for the profile, about block, social links and media. Social links and tab preferences are normalized into child tables with foreign-key cascade deletion.

Profile media is **not** written beneath the public document root. Avatar and banner objects use the storage driver with `private` visibility and are served only after the profile visibility policy has approved the viewer.

## Visibility and authorization

The current safe policy deliberately has no synthetic `isStaff` override. Before the shared role/permission engine in roadmap step 05.x exists, only the profile owner can edit profile data or media. Public profiles are guest-readable, members profiles require an authenticated viewer, and private profiles are owner-only. Section visibility is evaluated only after the profile itself is visible.

`ProfileAccessPolicy` is the integration boundary for the later shared permission engine. A future staff/moderation override must be implemented by that policy layer rather than by trusting request parameters.

The native web adapter resolves a viewer only from the configured authentication-session cookie through `AuthSessionManager`, then re-loads the user and requires an account that can authenticate normally. Query parameters, route parameters and form fields cannot promote a visitor to member/staff visibility. Invalid or missing session cookies become anonymous; infrastructure failures are not silently downgraded.

Private/hidden profiles and hidden media use the same `404 Not Found` response as unknown members so the web surface does not add a separate privacy-state oracle.

## Native PHP web surface

The cPanel-safe native surface is composed in `Forwext\App\Web\WebApplicationFactory` and dispatched through the shared Router. The public entry point remains a thin bootstrap/response emitter and contains no profile SQL.

Routes introduced by 04.06 are:

- `/members` — active profiles whose overall visibility is public;
- `/members/{username}` — the visibility-filtered member profile;
- `/members/{username}/avatar` — policy-checked private avatar delivery;
- `/members/{username}/banner` — policy-checked private banner delivery.

The directory is intentionally public-only. Member-only/private profiles are not advertised there even when an authenticated viewer could open them directly. The profile view supports the persisted `overview` and `about` presentation tabs at this stage. Unknown future tab keys and the not-yet-implemented activity feed are not rendered as fake content.

Apache/LiteSpeed deployments use `public/.htaccess` to route non-file requests to the front controller. Canonical URL path data is converted to the existing Router `BasePath`, so subdirectory installations keep generated member/media links inside the configured application path.

## Media pipeline

Avatar uploads are limited to 8 MiB and 4096×4096. Banner uploads are limited to 16 MiB and 8192×4096. Only JPEG, PNG and WebP payloads accepted by PHP's image-header parser are stored. SVG is intentionally rejected. Filenames are content-addressed with SHA-256 and the extension is derived from the detected image MIME type.

Replacement writes the new private object first, updates the database reference second and best-effort deletes the old object last. If the database update fails, the newly staged object is removed best-effort. This ordering prevents a successful database update from pointing at an object that was never written.

Media delivery re-checks visibility on every request and re-inspects the stored image payload before returning it. Responses use the detected image MIME type, `X-Content-Type-Options: nosniff` and private/no-store caching.

## Rendering contract

About text, usernames, social URLs/labels and tab labels are untrusted input. Native PHP views escape them with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. About text is plain text at this stage and is never rendered as raw HTML. Social links are restricted by the `SocialLink` value object to absolute HTTPS URLs without embedded credentials and render with `nofollow noopener noreferrer`.

## cPanel/runtime impact

No worker, daemon, Redis service, Node.js process or image-manipulation extension is required. The default MySQL/PDO database, file authentication-session store and local private-storage driver remain valid on cPanel.

The profile domain/media service continues to depend on the generic `StorageDriver`, so S3-compatible private storage remains an advanced composition option. The stock web composition intentionally wires the cPanel-safe local driver and fails explicitly instead of silently downgrading when a different advanced runtime driver is selected without its required adapter composition.
