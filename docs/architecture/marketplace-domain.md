# Marketplace domain and category system

Substep 14.01 establishes the first-party marketplace domain without prematurely implementing 14.02 listing discovery/reviews or later external-link/payment/order flows.

## Listing model

A listing has a stable id, immutable seller identity, category, unique slug, title, long-form description, minor-unit price, three-letter currency code, tags, internal media metadata, typed category custom values, state and UTC timestamps.

Prices are integer minor units rather than floating-point values. This avoids binary floating-point rounding in later checkout/accounting work.

The lifecycle is:

draft -> pending -> active -> paused / sold / closed -> archived

New listings must start as draft. Sellers with marketplace.listing.manage_own can edit their own listing and submit/pause/mark sold/close only through explicit service transitions. marketplace.listing.manage_all is required for approval, archival and managing another seller's listing. Seller identity is immutable after creation.

Public visibility in 14.01 is limited to active and sold states. Search/list/grid UX is intentionally owned by 14.02.

## Category hierarchy

Categories have stable ids, unique key/slug, optional parent, enabled state and sort order. Administration rejects self-parenting, cycles, missing parents and hierarchies deeper than eight ancestors.

The native /admin/marketplace/categories surface is protected by marketplace.category.manage and dedicated marketplace-category CSRF. It manages both category hierarchy and category-scoped custom fields.

## Custom fields

Supported first-party field types are text, integer, boolean and select.

Select fields require an explicit option list; other types cannot silently carry select options. Listing persistence validates each value against the active category field definition and refuses unknown or missing required fields.

This is deliberately typed storage, not arbitrary executable schema or templates.

## Media boundary

14.01 stores marketplace media metadata only. Media paths must be internal private-storage style paths under:

marketplace/<listing-id>/<sha256>.(jpg|png|webp)

Only JPEG, PNG and WebP metadata is accepted, a listing can reference at most 20 media records, and every media path must belong to that listing id. The actual upload/inspection delivery UX is implemented with listing UX rather than allowing arbitrary URLs into this domain.

## Permissions and audit

The existing marketplace listing permissions remain authoritative:

- marketplace.listing.view
- marketplace.listing.create
- marketplace.listing.manage_own
- marketplace.listing.manage_all

14.01 adds marketplace.category.manage for category/custom-field administration.

New-user templates may view but not create/manage. Member and verified templates can create/manage their own listings. Moderator templates can manage all listings but do not receive category-schema administration by default. Administrator templates receive category and listing management.

Category/custom-field changes and staff approval/archive actions use central administration audit. Listing create/update/state transitions also append marketplace-specific lifecycle history.

## Schema

Migration 20260919210000_marketplace_domain creates:

- marketplace categories;
- listings;
- listing tags;
- listing media metadata;
- category custom-field definitions;
- listing custom-field values;
- listing lifecycle history.

The migration is additive and leaves future 14.02+ review, featured/pinned, external-link, payment and order schemas to their owning substeps.
