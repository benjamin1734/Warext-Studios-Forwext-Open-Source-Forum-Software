# 15.05 — Marketplace, Revenue, Referral and Giveaway Analytics

## Scope

The 15.05 analytics surface covers the binding roadmap requirements for listings, clicks, orders, GMV/revenue, external CTR, referrals, campaign conversions and giveaway participation.

Route: `GET /admin/analytics/commerce?days=7|30|90`.

The route uses the shared backend `analytics.view_site` permission. Frontend visibility is not an authorization boundary.

## Marketplace funnel

Authoritative data comes from Marketplace domain tables:

- listing creation/current active inventory: `forwext_marketplace_listings`;
- listing views: privacy-aware structural analytics event `marketplace.listing.view`;
- external outbound clicks: `forwext_marketplace_external_sale_clicks`;
- order creation and lifecycle transitions: `forwext_marketplace_orders` and `forwext_marketplace_order_history`.

External CTR is external clicks divided by recorded listing views in the same UTC window. The listing-view producer is introduced by 15.05 and is intentionally not backfilled, so earlier traffic does not silently become inferred analytics.

## GMV and payment flow

Paid-order GMV is calculated from the first transition into `paid` recorded by Marketplace order history, joined to the authoritative order total. This avoids treating later order updates as new paid volume.

Successful refunds are taken from `forwext_payment_refunds` and joined to the payment attempt for currency. Currency rows remain strictly separate; TRY, USD, EUR or other currencies are never added together.

`net payment flow = paid GMV - succeeded refunds in the selected window`.

Net payment flow may be negative when a refund in the selected period belongs to an older sale. It is not presented as platform profit because the current Marketplace schema has no authoritative commission/fee field.

## Revenue

The dashboard also exposes advertising estimated revenue from `forwext_ad_campaigns` and `forwext_ad_events`. This is explicitly labelled estimated revenue because it uses configured impression/click value fields rather than payment settlement records.

## Referral conversion

Referral metrics use only campaign and lifecycle metadata:

- `forwext_referral_clicks`;
- `forwext_referral_attributions`;
- `forwext_referral_rewards`.

The selected-window attribution cohort is split into current review/qualified/rejected states. Campaign rows expose click → attribution and attribution → qualified conversion rates plus granted reward units.

IP/device fingerprints are not queried by analytics.

## Giveaway participation

Giveaway metrics use:

- `forwext_giveaways`;
- `forwext_giveaway_entries`;
- `forwext_giveaway_draws`.

The dashboard reports created giveaways, unique participating users, weighted entry totals and draw/redraw count. Network/device fingerprints from giveaway anti-abuse data are not queried.

## Privacy and security

- backend permission enforcement is mandatory;
- responses are `private, no-store` and `noindex,nofollow`;
- listing views use installation-specific pseudonymous actor/session hashes plus a structural listing identifier;
- no payment billing/receipt JSON, outbound target URL, referral IP/device fingerprint or giveaway network/device fingerprint is selected;
- no new raw identity tracking field is introduced.

## Performance and deployment

Migration `20260923195000_commerce_analytics_indexes` adds additive, idempotent time-window indexes to existing authoritative tables. It does not reset data.

The implementation stays within the native PHP + MySQL/MariaDB cPanel-first profile. No Composer/npm/Node/Redis/worker/WebSocket/Docker/Supervisor runtime dependency is introduced.
