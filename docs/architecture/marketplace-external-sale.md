# Marketplace External-Sale Redirect Contract (14.03)

## Scope

Forwext supports marketplace listings whose transaction is completed on an approved external sales site. This sub-step intentionally does not create carts, orders, payment states, seller balances, invoices or delivery records; those belong to the native purchase and later payment/delivery sub-steps.

## Configuration

The native runtime uses the following fail-closed configuration:

```php
'marketplace' => [
    'external_sale' => [
        'allowed_hosts' => [],
        'allow_subdomains' => false,
        'utm_source' => 'forwext',
        'utm_medium' => 'marketplace',
    ],
],
```

An empty `allowed_hosts` list allows no external destination. Hosts must be explicit ASCII DNS names. IP literals, credentials, fragments, custom ports, non-HTTPS schemes and non-allowlisted hosts are rejected. Optional subdomain allowance uses DNS-label boundaries, not suffix substring matching.

## Permission and ownership

- `marketplace.external_link.use` is resolved through the shared permission engine.
- Configuration also requires the existing own/all listing management boundary.
- Removing the seller's external-link permission immediately removes the public purchase handoff even if a previously valid row remains stored.
- New-user template default is deny; member, verified, moderator and administrator templates receive allow defaults in migration `20260919212000_marketplace_external_sale`.
- Frontend visibility is not treated as authorization; service methods repeat the backend checks.

## Redirect flow

1. An active listing with an enabled and currently valid external-sale link exposes a Forwext-internal purchase action.
2. The user first reaches `GET /marketplace/listings/{listingId}/external`.
3. Forwext displays a warning with only the validated destination host and explains that checkout, delivery, privacy and refund terms belong to the external site.
4. Continuing requires a CSRF-protected `POST /marketplace/listings/{listingId}/external/go`.
5. The redirect target is loaded from server-side persisted configuration; no URL is accepted from the warning/go request.
6. The stored URL is revalidated against the current allowlist and its stored host binding immediately before the redirect.
7. Missing UTM source/medium/campaign values are appended without overwriting values already supplied by the seller.
8. The 303 response uses `Referrer-Policy: no-referrer`, `Cache-Control: no-store` and `X-Robots-Tag: noindex,nofollow`.

This design prevents a generic user-controlled open-redirect endpoint and makes allowlist changes effective without rewriting stored listing data.

## Click tracking and privacy

Confirmed handoffs are recorded in `forwext_marketplace_external_sale_clicks` with listing id, optional authenticated viewer id, validated target host and UTC timestamp. Raw referrer URLs, IP addresses, browser strings and the full destination query string are not copied into the click table.

Click recording is analytics fail-open: a transient analytics write failure does not block a destination that has already passed the permission and URL security policy. Later analytics work can aggregate the durable click rows.

## Audit

Seller/staff configuration changes are recorded through the central audit recorder. Audit before/after snapshots contain only the validated host and enabled state, avoiding accidental query-token leakage from full external URLs.

## Data model

Migration `20260919212000_marketplace_external_sale` adds:

- `forwext_marketplace_external_sale_links`
- `forwext_marketplace_external_sale_clicks`
- explicit permission definition/defaults for `marketplace.external_link.use`

The link row is one-to-one with a listing and is deleted with the listing. Anonymous clicks remain anonymous; authenticated viewer references are nullable and become null if that account is removed.

## UX and deployment

The feature is implemented in the native PHP frontend and requires no Composer/npm/Node/Redis/worker/WebSocket dependency. Sellers manage the link from the existing marketplace listing-management flow. The allowlist remains a runtime/integration configuration concern so cPanel deployments stay simple and advanced deployments can later expose the same keys through the System/Integration ACP without changing this domain contract.
