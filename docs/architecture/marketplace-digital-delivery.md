# Marketplace Digital Delivery and Order Management (14.06)

## Scope

Forwext owns digital-delivery configuration, immutable order-item delivery snapshots, fulfillment state, buyer access, order history and Support integration. Delivery is part of the first-party Marketplace domain and reuses common permissions, audit, private storage, attachment inspection, payment settlement and Support infrastructure.

The minimum cPanel profile requires no queue worker, Redis, object storage or external delivery service. Advanced deployments may replace storage/queue implementations behind existing contracts without changing Marketplace domain rules.

## Delivery modes

A listing can use one of four delivery types:

- `download`: a private asset is snapshotted onto the order item and becomes available after eligible payment settlement;
- `license`: an encrypted value from the listing key pool is reserved for the order item and revealed only through authorized delivery access;
- `key`: identical reservation/security mechanics to license delivery, retained as a distinct semantic type for product UX;
- `manual`: a seller or authorized delivery manager supplies the fulfillment payload after payment.

Checkout snapshots the configured type and download asset id into each order item. Later listing configuration changes therefore do not mutate historical order semantics.

## Private assets

Delivery uploads use the shared verified HTTP-upload reader and attachment inspector before storage. The first-party allowlist currently accepts ZIP, PDF, plain text and safe image types supported by the inspector.

Assets are stored with private visibility under a listing-bound storage path. Metadata includes original safe filename, media type, byte size and SHA-256 digest.

Buyer download re-reads the private object and verifies both stored size and SHA-256 before sending bytes. The response uses:

- authenticated order access;
- `Cache-Control: private, no-store`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: no-referrer`;
- encoded attachment disposition.

The public web tier never exposes a raw storage path.

## License and key pools

License/key values are normalized and encrypted with the shared authenticated secret cipher before persistence. A keyed HMAC fingerprint provides duplicate detection without storing plaintext hashes that are reusable outside the installation secret boundary.

Checkout reserves an available key inside the order transaction. Reservation is tied to the exact order-item id. Payment settlement activates reserved values. Cancellation releases reservations that have not been delivered.

Plaintext values are revealed only through the authorized delivery service. They are not rendered into the ordinary order-detail GET response, audit snapshots or logs.

## Payment and fulfillment lifecycle

14.05 remains authoritative for provider payment state. 14.06 listens to the normalized paid-order event and advances automatic delivery records only when the Marketplace order is eligible.

Automatic deliveries become `ready` after successful payment. Manual deliveries remain pending until seller fulfillment. Buyer reveal/download advances an eligible record to `delivered`; aggregate order delivery state is synchronized from all item records, and a confirmed order becomes completed only when every delivery is delivered.

Cancelled orders cannot be used as a delivery-access shortcut, and cancellation releases reserved license/key inventory.

## Permissions and IDOR boundary

Shared permission keys:

- `marketplace.delivery.manage_own`: configure/fulfill delivery for listings and sales owned by the actor;
- `marketplace.delivery.manage_all`: manage delivery across sellers.

Buyer access is derived from immutable order ownership rather than a UI control. Seller/staff management is checked again in backend services. Order and item ids are always rebound to the authorized order snapshot before fulfillment/reveal/download so a route parameter cannot be substituted to access another order item.

Unrelated users receive unavailable/not-found behavior instead of existence disclosure.

## Native PHP routes

Delivery configuration:

`GET|POST /marketplace/manage/delivery/{listingId}`

Order-item actions:

`GET|POST /marketplace/orders/{orderId}/delivery/{itemId}/{action}`

where `action` is one of `download`, `reveal` or `fulfill`.

Human mutations use the existing Marketplace CSRF middleware. Reveal intentionally requires POST so sensitive plaintext is not returned by a normal navigational GET. Download is GET-only after authorization and integrity verification.

## Support and disputes

`marketplace_order` is a first-party Support context type. A buyer, seller or actor with explicit order/delivery management authority can attach an order to a Support ticket. Unrelated actors cannot use the resolver to test whether an order id exists.

The native order page links directly to Support with the context type/id and a bounded order-number query hint. Support remains the owner of ticket lifecycle and attachments.

## History and audit

Marketplace order state transitions keep append-only order history including order, payment and delivery before/after states. The native order page exposes authorized history for troubleshooting.

Human administrative delivery configuration, key-pool mutation and manual fulfillment use central audit/request-id plumbing. Secret plaintext is excluded from audit snapshots.

## Migration and upgrade

Migration `20260919215000_marketplace_digital_delivery` is idempotent and upgrade-safe. It:

- creates delivery asset, listing-setting, key-pool and order-item-delivery tables;
- adds delivery type/asset snapshots to existing order items;
- initializes delivery records for existing order items;
- installs delivery permission definitions and template defaults.

Normal update does not reset Marketplace/order/user data.

## Verification

14.06 completion is gated by:

- strict-types and PHP lint;
- PHPUnit on PHP 8.4 and PHP 8.5;
- MySQL 8.4 and MariaDB 10.11 migration smoke;
- post-install web bootstrap smoke;
- all ten core navigation routes tested in both root and `/public` subfolder deployment paths with 404/5xx rejection.
