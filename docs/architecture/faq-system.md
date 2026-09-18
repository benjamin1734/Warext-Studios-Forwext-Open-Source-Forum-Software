# FAQ System

Roadmap sub-step **10.04** introduces the first-party FAQ/SSS domain. Ticket-to-FAQ recommendations and draft generation remain exclusively in 10.05.

## Content model

FAQ content is split into ordered categories and articles.

A category has:

- stable key;
- label and description;
- BCP-47-style language tag;
- visibility;
- sort order;
- active state.

An article has:

- immutable 32-hex article id;
- category key;
- language-local SEO slug;
- question and answer;
- up to 32 normalized tags;
- visibility;
- sort order;
- optional SEO title/description;
- active state;
- created/updated UTC timestamps.

The database enforces a unique `(language, slug)` pair.

## Visibility

Visibility levels are:

- `public`
- `members`
- `staff`

The effective article visibility is always the more restrictive of article visibility and category visibility.

Public content is available without authentication. Member content requires `faq.view` (or `faq.manage`). Staff content requires `faq.manage`.

The same effective visibility is projected into the search index:

- public -> `public`
- members -> `faq.members`
- staff -> `faq.staff`

`FaqSearchAccessScopeProvider` derives those scopes server-side from the permission engine. Search query input cannot manufacture FAQ access scopes.

## Helpful analytics

Authenticated viewers who can access an article may mark it helpful or not helpful.

Votes use `(article_id, user_id)` as the primary key. Re-voting updates the previous answer instead of increasing the vote count, preventing trivial repeated-vote inflation by one account.

The public article surface shows aggregate helpful/not-helpful totals only. User identities are not exported.

The management surface shows per-article vote count and helpful ratio.

## Import/export

`FaqService::export()` produces versioned JSON:

- format: `forwext-faq`
- version: `1`
- categories
- articles

Analytics/user identities are deliberately excluded.

Import accepts at most 5 MB, 500 categories and 10,000 articles per operation. Every imported row passes the same typed domain validation used by native management.

Import is one database transaction. Category changes also queue every existing article in that category for search re-indexing, so a visibility/language policy edit cannot leave stale search scopes.

## Global search

`DatabaseFaqSearchContentSource` registers `faq.article` with the existing native search lifecycle.

Only active articles in active categories produce search documents. Indexed content includes:

- question as title;
- answer as body;
- article language;
- tags;
- active state;
- effective access scope.

Article/category saves queue `faq.article` index changes through the existing search change store. The normal search drain/rebuild infrastructure performs the actual upsert/delete.

The global discovery registry already owns `faq.article` under the SSS category. Search results link to an id-based permission-aware redirect and then to the canonical FAQ slug.

## SEO and discovery

Public active FAQ articles participate in:

- canonical FAQ URL `/faq/{language}/{slug}`;
- index/follow metadata;
- custom SEO title and description;
- Schema.org `FAQPage` JSON-LD;
- sitemap;
- RSS/Atom public discovery feeds.

The SEO database reader explicitly requires both article and category visibility to be `public`. Member/staff FAQ pages therefore remain noindex.

The public FAQ collection page `/faq` is indexable as a CollectionPage.

## Native web surfaces

- `GET /faq` — visible FAQ categories/articles with optional language filter.
- `GET|POST /faq/{language}/{slug}` — canonical article and helpful vote.
- `GET /faq/articles/{articleId}` — permission-aware redirect used by global search.
- `GET|POST /faq/manage` — category/article management and JSON import/export; requires `faq.manage`.

All FAQ mutations use a dedicated same-origin CSRF scope. All rendered user-managed fields are HTML-escaped.

The main navigation exposes a public SSS link.

## Database

Migration `20260918014000_faq_system` creates:

- `forwext_faq_categories`
- `forwext_faq_articles`
- `forwext_faq_article_tags`
- `forwext_faq_helpful_votes`

It also ensures `faq.view` and `faq.manage` permission definitions/template defaults and creates a safe editable `general` Turkish public category.

## Roadmap boundary

10.04 intentionally does **not** inspect ticket text or suggest FAQ answers during support creation. It also does not create FAQ drafts from staff replies. Those workflows belong to 10.05.

Support dashboard/My Tickets/SLA reporting/support audit remain 10.06.
