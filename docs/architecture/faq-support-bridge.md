# FAQ ↔ Support Bridge

Roadmap sub-step **10.05** connects the 10.04 FAQ system to the 10.01–10.03 support-ticket system without creating a second permission/search/content model.

## Ticket-opening recommendations

The native `/support/new` flow now accepts a bounded `q` problem/question query alongside the selected support category.

The bridge first asks `DatabaseFaqSupportBridgeRepository` for a bounded candidate-id set using:

- exact support-category ↔ FAQ-category match;
- exact support-category ↔ FAQ-tag match;
- bounded token matches against FAQ question/answer/tag data.

This candidate query is **not authorization**. Every candidate id is subsequently resolved through `FaqService::article(actor,...)`, so inactive, member-only or staff-only FAQ content cannot leak to a requester through recommendations.

PHP ranking then applies deterministic weights for category, tag, question, answer and helpful-ratio signals. At most five recommendations are rendered in the normal support form. The query text is also prefilled into the ticket subject field so the user does not have to type it twice.

No mandatory JavaScript, Redis, worker or external-search dependency is introduced.

## Post-resolution guidance

For `resolved` and `closed` tickets, the permission-aware ticket detail screen runs the same recommendation engine against:

- ticket category;
- ticket subject;
- original ticket description.

Matching visible FAQ entries are shown as direct canonical links under **Çözüm sonrası ilgili SSS**.

Active tickets do not show this post-resolution block.

## Staff reply → FAQ draft

A new granular permission is introduced:

`support.faq_draft.suggest`

Built-in moderator/administrator templates receive allow; normal templates receive deny.

Only a **public staff conversation message belonging to the same ticket** can become a draft suggestion. Internal notes, requester messages and cross-ticket message ids are rejected in the domain service.

A suggestion snapshots:

- ticket id;
- source message id;
- suggesting staff user id;
- optional FAQ category hint;
- ticket subject as proposed question;
- staff public answer as proposed answer;
- pending/applied/rejected state;
- creation/update timestamps.

Each source message can create at most one stored suggestion.

## FAQ-manager review

`GET|POST /faq/manage/support-drafts`

uses the existing FAQ CSRF scope and `faq.manage` permission.

A FAQ manager may:

- reject a pending suggestion; or
- choose a FAQ category + slug and convert it into a normal FAQ article draft.

Applied drafts deliberately create an article with:

- `active=false`;
- `visibility=staff`;
- category language;
- `support-draft` tag;
- optional category-key tag;
- question/answer snapshots from the support suggestion.

Therefore ticket staff cannot publish public FAQ content merely by suggesting it. Normal FAQ management/editing remains the publication boundary.

Article creation and draft-state transition share one database transaction. `FaqService::saveArticle()` now joins an existing transaction instead of starting a nested one, while retaining search-index change recording.

## Persistence

Migration `20260918015000_faq_support_bridge` creates:

`forwext_faq_support_drafts`

with ticket, source-message, suggester and optional FAQ-category foreign keys plus source-message uniqueness and pending-review indexes.

The migration is additive/idempotent and seeds `support.faq_draft.suggest` permission/template defaults.

## Security properties

- Recommendation candidate SQL never grants access.
- Final FAQ visibility is always rechecked through the 10.04 FAQ service.
- Ticket detail access remains protected by the 10.01 own-vs-all IDOR/BOLA boundary before any draft mutation.
- Draft creation revalidates message ticket ownership, staff role and public visibility.
- FAQ draft review requires `faq.manage`.
- Suggested content is escaped by support/FAQ native HTML renderers.
- Applying a suggestion does not auto-publish content.
- Candidate scan and token counts are bounded.

10.06 remains responsible for My Tickets, staff dashboard, SLA/category statistics and support-wide audit/reporting.
