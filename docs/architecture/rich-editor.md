# Rich Editor, Safe Rendering and Live Metrics

Roadmap step 06.07 adds the shared editor used by thread and post composition surfaces. The editor keeps **source text** as the persisted form and treats rendered HTML as a derived view. It does not introduce a migration.

## Source and render contract

`BbCodeRenderer` is the canonical safe renderer for preview and final-content rendering integrations.

Supported first-party syntax includes:

- `[b]`, `[i]`, `[u]`, `[s]`
- `[quote]...[/quote]` and a safely escaped quote label
- `[code]...[/code]` where inner BBCode is not executed
- `[url]https://...[/url]` and `[url=https://...]label[/url]`
- `[mention=<stable-user-id>]`
- `[embed]https://...[/embed]` or the attribute form

Normal source text is HTML-escaped. Raw HTML is never passed through. URL and embed targets pass through `SafeEditorLinkPolicy`, which permits only credential-free HTTPS URLs or unambiguous site-relative paths. `javascript:`, protocol-relative URLs, credential-bearing URLs, control bytes and backslash-ambiguous targets remain non-executable text.

The renderer rejects source larger than 100000 bytes, invalid UTF-8 and unsafe control characters. Recursive formatting is capped at 32 levels so malicious nesting cannot create unbounded parser recursion.

## Mentions

Persisted mention syntax contains the immutable user id rather than the typed display name. `UserMentionResolver` converts that id to the current canonical username at render time and generates a base-path-aware member URL. This prevents a later username change from causing an old mention to silently point at a different account.

The native web editor exposes authenticated exact-username lookup at `GET /editor/mention`. The response contains only the stable id, public mention label and public profile URL; it does not expose email or other private account data.

## Embeds

Step 06.07 intentionally does not inject arbitrary third-party HTML or iframes. `SafeLinkEmbedResolver` creates a first-party link-card representation from a URL already accepted by the safe link policy. This satisfies embed behavior while preserving the existing CSP and avoiding remote script/frame execution. Future provider-specific rich embeds must remain behind the `EmbedResolver` boundary and require their own security policy.

## Preview

`EditorPreviewService` performs the same server-side metrics assessment and uses the same `BbCodeRenderer` intended for final rendering integrations. The native route `POST /editor/preview` requires an authenticated session and returns JSON containing:

- sanitized rendered HTML;
- validity state;
- character, word and byte counts;
- active minimum/maximum limits;
- deterministic violation codes.

Preview is side-effect-free and writes no state. It therefore does not become a substitute for the CSRF and permission checks on the eventual thread/post mutation endpoint. Responses are private/no-store.

## Live character and word counters

`EditorTextMetrics` counts Unicode code points and Unicode letter/number words without requiring Mbstring. The default body limits are aligned with the existing `PostBody` hard cap:

- minimum characters: 1;
- maximum characters: 100000;
- maximum UTF-8 bytes: 100000;
- minimum words: 0;
- maximum words: unrestricted by default.

A zero default minimum-word limit is deliberate: `PostBody` already accepts valid non-word Unicode content such as emoji, so the editor must not introduce a stricter hidden rule.

`RichEditorView` renders reusable `thread` and `post` editor surfaces with toolbar controls, preview, live character/word/byte counters and visible min/max indicators. `public/assets/rich-editor.js` mirrors the server metrics for immediate UX and uses `setCustomValidity()` to prevent accidental browser submission when current limits are violated. This JavaScript is advisory UX only; server-side validation remains authoritative.

The assets are external same-origin files and therefore remain compatible with the existing CSP without adding inline JavaScript or third-party script hosts. The CSS includes responsive layout and reduced-motion handling.

## Security invariants

1. Raw HTML is escaped, never trusted.
2. Preview and final renderer integrations share one renderer implementation.
3. Final thread/post authorization remains in the existing actor-bound permission services; preview never grants posting authority.
4. Mention identity is stable-id based, not display-name based.
5. Exact mention lookup requires authentication and exposes only public identity data.
6. External URL schemes are HTTPS-only; no credentials or protocol-relative URLs.
7. Embed output is first-party safe markup, not arbitrary iframe/provider HTML.
8. Source byte size and recursive nesting are bounded before expensive rendering.
9. Client counters do not replace server validation.
10. No database migration is required for this sub-step.
