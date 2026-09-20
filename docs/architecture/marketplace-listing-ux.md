# Marketplace listing UX, discovery and reviews

Substep 14.02 turns the 14.01 marketplace domain into a usable first-party discovery surface. It intentionally does not implement external sales redirects or checkout/order/payment behavior; those remain 14.03 and later marketplace steps.

## Public discovery

The native PHP marketplace surface is available at `/marketplace`.

Discovery supports:

- grid and list presentation;
- text, category, tag and ISO-style currency filters;
- minimum and maximum price filters;
- featured-only filtering;
- featured, newest, price ascending, price descending and rating sorting;
- bounded pagination;
- pinned and featured placement;
- sold-state badges while keeping sold listings publicly discoverable;
- cover images from the first ordered listing media item.

The browse repository uses a single card projection query for seller identity, category, promotion state and visible review aggregates rather than issuing per-card category/seller/review queries.

## Seller integration

`/marketplace/sellers/{username}` is the seller storefront and paginates public listings. It links back to the canonical forum profile.

The profile system includes a public `marketplace` tab. The tab uses the same public listing query and therefore does not expose draft, pending, paused, closed or archived listings.

Marketplace is also registered in the core navigation and in global discovery search.

## Search lifecycle

Document type `marketplace.listing` indexes only active and sold listings. Search documents include:

- listing title;
- description;
- category name;
- tags;
- seller USER attribute;
- listing STATE attribute;
- TAG attributes.

Authenticated search access requires the backend-authoritative `marketplace.listing.view` permission and the `marketplace.members` search scope.

Listing create/update/lifecycle changes record a search-index change. Migration `20260919211000_marketplace_discovery_ux` also queues existing active/sold listings for reindex so upgrading installations are not left with an empty Marketplace search corpus.

## Reviews

A signed-in user with `marketplace.review.create` may maintain one review per listing. Ratings are 1-5 and review text is passed through the common content pipeline when available.

Sellers cannot review their own listings.

The unique database key on listing/user and update-in-place domain flow make review submission idempotent per reviewer. Visible reviews contribute to listing card/detail aggregates. Pending and hidden reviews do not.

`marketplace.review.manage` is separate from listing-management permissions. Staff moderation changes review state and writes central administration audit.

## Featured and pinned placement

`marketplace.feature.manage` is a separate staff capability. Promotion records contain optional UTC featured/pinned expiry times and the actor who updated them.

Only public listings may be promoted. Promotion changes are centrally audited. An expired timestamp automatically stops affecting browse ordering without requiring a cleanup job.

Pinned listings remain ahead of ordinary listings. Featured sort also prioritizes active pinned/featured placement before recency.

## Listing management

`/marketplace/manage` is backend-authorized:

- users with own-listing permissions see only their listings;
- staff with manage-all can inspect all listings;
- creation starts from a selected enabled category;
- typed category custom fields are rendered and revalidated by the existing 14.01 domain service;
- state changes still use the 14.01 transition methods rather than accepting arbitrary state form input.

The UI exposes submit, staff approval, pause, sold, close and staff archive actions only when appropriate, but backend transition/permission checks remain authoritative.

## Media security

Marketplace images are stored through the common attachment inspector and private storage driver.

Uploads:

- require owner/staff listing-management access;
- accept JPEG, PNG and WebP only;
- use sanitized inspected contents;
- are limited by the existing upload-size policy and 20-media listing limit;
- derive each private object digest from the sanitized bytes plus the random media id, so concurrent duplicate uploads cannot share one deletable storage object.

Public image URLs are controlled `/marketplace/media/{mediaId}` application routes rather than raw storage paths. The download service rechecks listing visibility/management access and verifies the stored bytes and media id against the SHA-256 binding encoded in the storage path before returning content with `nosniff`; the direct-content digest is accepted only as a compatibility path for the earliest 14.02 development records.

Private listing media is therefore not made public merely by knowing a storage path.

## Permissions added in 14.02

- `marketplace.feature.manage`
- `marketplace.review.create`
- `marketplace.review.manage`

These complement the 14.01 category/listing permissions instead of replacing them.

## Deferred boundaries

14.03 owns the external-link redirect mode, domain allowlist, redirect warning/counting and UTM support.

Internal checkout/order/payment, disputes, commissions, seller balance, delivery and refund behavior are intentionally not implemented by 14.02.
