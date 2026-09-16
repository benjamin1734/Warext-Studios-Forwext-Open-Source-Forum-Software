# Mention, Quote, Embed, Link Preview and Emoji (07.02)

## Scope

07.02 extends the existing 06.07 rich-editor/rendering foundation without replacing it. The editor now provides authenticated mention autocomplete, permission-aware cross-thread quoting, the existing iframe-free safe embed contract, SSRF-resistant link previews and a first-party emoji/smiley catalog.

No new persistent data is introduced by this sub-step, so no migration is required.

## Mention autocomplete

Historical mentions remain stable-id based: rendered content stores `[mention=<user id>]`, not a mutable username lookup. Username changes therefore do not retarget old mentions.

`DatabaseMentionSuggestionProvider` adds bounded prefix suggestions without changing the general `UserRepository` contract. It:

- accepts only bounded username-shaped prefixes,
- escapes SQL wildcard characters,
- returns at most 10 rows,
- returns only `active` users,
- orders results deterministically,
- exposes only stable id, username/label and the public profile URL.

The `/editor/mention` endpoint remains authenticated and keeps its older exact `username=` lookup for compatibility. `q=` activates autocomplete. The browser debounces typing and replaces the typed `@prefix` with a stable-id mention token after selection.

## Cross-thread quote authorization

`CrossThreadQuoteService` never trusts the current editor/thread as proof that a source post may be read. It loads the source post and thread, then requires `forum.view` on the source forum.

A normal visible post in a visible thread can be quoted by an actor who can view that forum. Deleted, pending or otherwise non-visible source content is available only to its owner or an actor with `forum.post.moderate` in the source forum. The HTTP adapter maps unavailable/unauthorized source content to the same privacy-preserving 404 result.

Quoted source is capped at 12,000 Unicode characters. Source BBCode is not inserted as executable nested BBCode. It is base64url encoded into an internal `[plain64=...]` token and decoded only by the server renderer, which validates size/UTF-8/control characters and escapes the decoded source. A source post containing `[/quote]`, `[b]`, HTML or another parser token therefore cannot break out of the quote container.

## Safe embed

The pre-existing safe embed architecture remains authoritative. `[embed]` produces an iframe-free first-party card containing a normalized link and label; it never accepts provider HTML, arbitrary scripts, external iframe markup or user-supplied executable attributes. This preserves the existing restrictive CSP.

## SSRF-safe link preview

Link preview is deliberately separate from normal link normalization because it performs server-side network I/O.

`LinkPreviewUrlPolicy` requires:

- credential-free `https://` URLs only,
- port 443 only,
- no URL fragments,
- no IP-literal hosts,
- a public multi-label hostname,
- no localhost, `.localhost`, `.local`, `.internal`, `.test` or `.invalid` namespace,
- successful DNS resolution,
- **every** resolved A/AAAA address to be public and non-reserved.

Rejecting a host when even one resolved address is private/reserved prevents mixed-answer rebinding tricks from passing validation.

`PinnedHttpsLinkPreviewTransport` does not hand the approved hostname back to a normal URL client for a second DNS lookup. It opens TLS directly to one of the already-approved IP addresses while setting SNI and certificate peer-name verification to the original hostname. This closes the validation-to-connect DNS rebinding gap.

Transport limits are deliberately small: four-second connection/read timeout, bounded response lines/headers, at most 256 KiB body, identity encoding, no automatic redirects and no arbitrary protocol handling. Redirects are processed by `LinkPreviewService`; every redirect target goes through the entire URL/DNS policy again and at most three redirects are followed.

Only successful HTML/XHTML responses are parsed. The service extracts bounded plain-text title and description only. Remote scripts, HTML fragments and preview images are never returned to the editor, so previewing a link does not cause the user's browser to contact a third-party image host.

`POST /editor/link-preview` requires an authenticated viewer and the same-origin editor's `X-Forwext-Editor: 1` custom header before any outbound network work starts. This header is a cross-site request trigger barrier (ordinary cross-site HTML forms cannot set it); it is not described as a replacement for authentication or the SSRF network policy.

## Emoji and smileys

`EmojiCatalog` is a bounded first-party static catalog. The editor inserts stable `[emoji=<key>]` tokens and the renderer emits escaped/accessibility-labelled local Unicode emoji. Common textual smileys and named aliases are transformed only in normal text. Code blocks remain literal, so code examples such as `:)` or `[emoji=fire]` are not rewritten.

No external emoji CDN, image URL or script is required.

## Browser UX

The native editor adds:

- 150 ms debounced mention suggestions,
- cross-thread post quote insertion,
- server-side link-preview cards with DOM `textContent` rendering,
- a local emoji palette,
- public JS methods for stable-id mention insertion, post quoting and link previewing.

Remote preview metadata is never assigned to `innerHTML`. The existing rich-text preview endpoint continues to use server-generated sanitized renderer HTML.

## Deployment and extension boundary

The feature uses existing PHP/database/session/permission primitives. The network transport is implemented with PHP stream sockets and does not require cURL, Node, Redis, Supervisor or a worker daemon. Internationalized preview hostnames require `idn_to_ascii`; without that optional capability they fail closed instead of weakening hostname validation.

Future provider-rich previews may build on the curated `LinkPreview` result, but must not bypass `LinkPreviewUrlPolicy`, pinned-IP transport or redirect revalidation. Future add-ons that introduce outbound fetches must provide equivalent SSRF controls rather than calling arbitrary URLs directly.
