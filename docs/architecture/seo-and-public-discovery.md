# SEO and public discovery architecture — 08.04

## Scope

Forwext exposes canonical/meta/OpenGraph/JSON-LD metadata, `robots.txt`, XML sitemap, RSS and Atom without treating crawlers as an authorization boundary.

The implementation is fail-closed: an HTML route is `noindex,nofollow` unless it is explicitly recognized as public and, where applicable, independently re-verified as public in storage.

## Canonical source of truth

Absolute URLs are generated only from `routing.canonical_url`. Request `Host`, forwarded headers and query input never become canonical authority.

Subfolder installations are preserved through the canonical `BasePath`.

The current public profile canonical is the active custom `/u/{slug}` URL when one exists, otherwise `/members/{username}`. Both aliases can render, but metadata and sitemap output converge on one canonical URL.

## Private-content guarantees

`robots.txt` is advisory only and is never used as access control.

Profile metadata/sitemap/feed reads select only active accounts whose effective profile visibility is `public`. The SEO query does not select email, about text, private social links, media paths or authenticated-only content.

A profile that is renderable only because the current viewer is authorized remains `noindex,nofollow` and is excluded from sitemap/feed output.

Unknown, account, search, editor, attachment and other non-whitelisted HTML surfaces default to `noindex,nofollow`. Non-indexable non-HTML responses receive `X-Robots-Tag: noindex, nofollow`.

## Structured metadata

Public pages can emit:

- canonical URL
- meta description
- robots directive
- OpenGraph title/description/type/url
- Twitter summary card
- JSON-LD

JSON-LD is encoded with JSON hex escaping so user-controlled strings cannot break out of the script element.

Current JSON-LD types are `WebSite`, `CollectionPage` and public-profile `Person`.

## Sitemap and feeds

`/sitemap.xml` uses a source registry and is capped to the sitemap protocol maximum of 50,000 URLs.

`/feed.rss` and `/feed.atom` use the same public-discovery source boundary and currently syndicate latest public profile changes. Static site pages are present in the sitemap but not emitted as feed items.

Future public forum, FAQ, portfolio and marketplace HTML routes should register additional `PublicDiscoverySource` implementations rather than writing separate uncoordinated sitemap/feed queries. A module must not register a URL until the corresponding public route and its permission/privacy rules are real.

## cPanel/advanced deployment

The endpoints require no Node.js, Redis, daemon or worker. Sitemap/feed database reads use the normal PDO/MySQL configuration and encrypted secret store. Advanced deployments can cache or front these responses externally without changing the source contract.
