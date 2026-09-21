# Subscription / User Upgrades (14.07)

## Scope

Forwext owns first-party subscription and user-upgrade plans, timed/lifetime entitlement state, role and permission binding, renewal/expiry behavior and payment-provider integration.

The feature reuses the common role/permission engine, central audit stream, 14.05 payment-provider contracts and the first-class native PHP frontend. The minimum cPanel deployment does not require Redis, a queue worker, WebSocket infrastructure or a commercial payment SDK.

## Plans

A subscription plan contains:

- immutable internal id and stable plan key;
- public name and description;
- active/inactive sale state;
- integer minor-unit price and three-letter currency;
- optional duration in days, where `NULL` means lifetime;
- deterministic sort order;
- creator/updater and UTC timestamps.

Inactive plans disappear from the member purchase catalog but existing user entitlements remain intact. Changing a plan does not rewrite the price, currency or duration snapshot of an already-created purchase.

Zero-price plans are admin-assigned rather than silently self-claimable.

## Entitlements

Each user can have one durable aggregate subscription record per plan.

The record stores user and plan identity, `active` / `expired` / `revoked` state, start time, optional end time and audit-friendly timestamps.

Timed renewal extends from the existing future end time rather than from the payment time. A renewal after expiry starts from the new activation time. Lifetime upgrades have no end time.

Runtime authorization does not depend on an expiry cleanup job. Both role and permission overlays require subscription state `active` and either no end time or an end time greater than the database UTC clock. Therefore access disappears at expiry even if the durable row has not yet been normalized from `active` to `expired`.

## Role binding

Plans may bind eligible custom, unprotected roles.

Subscription roles are composed into the existing `UserAccessAssignment` at runtime and are de-duplicated with ordinary role assignments. The subscription system does not insert/delete rows in the normal manual user-role assignment table, so expiry/revocation cannot accidentally remove a role that was granted through another source.

Staff/system/protected roles are excluded from subscription role selection and runtime overlay.

## Permission binding

Plans may bind eligible flag permissions.

Active subscription permissions are added as runtime user-level allow rules to the existing permission engine. Existing explicit user denies retain deny precedence within the same user tier.

Administrative privilege classes are rejected from direct subscription-plan permission binding. The service blocks ACP, moderation, audit and payment namespaces plus management/override/refund/export-style capabilities and other explicitly sensitive first-party management permissions.

Numeric permissions are not directly granted by 14.07 plan bindings.

## Payment integration

Subscription purchases use the existing provider registry and provider contracts from 14.05.

A purchase snapshots user and plan, provider key, idempotency key, amount and currency, duration, normalized payment state, provider reference/action URL/error and activation timestamp.

Purchase initiation is idempotent and rejects a second active `pending`, `requires_action` or `authorized` purchase for the same user/plan. HTTPS checkout URL validation remains enforced by the shared payment provider result contract.

The provider receives a purchase-scoped id that is reused as the payment attempt/order correlation id for subscription billing.

## Webhooks

Provider callbacks use:

`POST /subscriptions/payments/webhooks/{providerKey}`

The route is intentionally outside browser-session CSRF because it is a server-to-server callback. It remains fail-closed behind provider cryptographic verification.

The service validates provider identity, provider reference, amount and currency. Webhook event ids are durable and provider-scoped for deduplication. Raw bodies are not stored; only SHA-256 payload hashes and normalized event metadata are persisted.

Payment state progression is monotonic so stale events cannot move a settled purchase backwards.

## Renewal and expiry

A paid purchase activates once. `activated_at_utc` makes repeated paid callbacks idempotent.

For timed plans, first activation creates a future end time; renewal before expiry extends from the prior end; renewal after expiry starts from the new activation time.

For lifetime plans the entitlement end remains `NULL`.

Expiry normalization is available through account read-repair, administration and bounded batch processing. Runtime authorization independently checks the real end time, so delayed normalization cannot extend access.

Manual admin grants use the same entitlement lifecycle and renewal rules as paid activation.

## Revocation

Authorized administrators can revoke a durable subscription assignment. Revocation immediately removes runtime role and permission overlays without mutating normal user role/permission assignments.

Plan deactivation is not retroactive revocation: users retain already-earned entitlement until expiry/revocation.

## Native PHP routes

Member surface:

- `GET /account/upgrades`
- `POST /account/upgrades/{planId}/purchase`

Administration:

- `GET|POST /admin/subscriptions`

Payment callback:

- `POST /subscriptions/payments/webhooks/{providerKey}`

Human mutation routes use dedicated subscription CSRF middleware. The verified webhook route deliberately does not.

The member navigation exposes `Upgrades`, and `/account/upgrades` is part of the post-install core navigation smoke suite for both root and `/public` subfolder deployments.

## Administration

The native administration page supports plan creation/editing and activation, price/currency and timed/lifetime configuration, role and flag-permission binding, manual grant/renew by username, assignment listing, explicit revocation and bounded expiry normalization.

All service mutations remain backend-authoritative; the HTML form is not a security boundary.

## Persistence

Migration `20260921220000_subscription_upgrade_system` creates:

- `forwext_subscription_plans`;
- `forwext_subscription_plan_roles`;
- `forwext_subscription_plan_permissions`;
- `forwext_user_subscriptions`;
- `forwext_subscription_purchases`;
- `forwext_subscription_webhook_events`;
- `forwext_subscription_events`.

It also installs subscription permission definitions and conservative built-in permission-template defaults. The migration is additive and does not reset existing users, Marketplace data, roles, permissions or payment data.

## Permissions

First-party subscription permissions:

- `subscription.view`;
- `subscription.purchase`;
- `subscription.manage_own`;
- `subscription.manage_all`.

Built-in defaults keep new users denied, enable normal member/verified account self-service where appropriate, and reserve global subscription administration for administrators.

## Verification

14.07 completion is gated by strict-types enforcement, PHP syntax lint, PHPUnit on PHP 8.4 and PHP 8.5, MySQL 8.4 and MariaDB 10.11 migration smoke, native web composition/security regression tests and root plus `/public` post-install navigation smoke including `/account/upgrades`.

Validated green runs for the completed implementation:

- Build installation package: `35627236876`;
- Database migration smoke: `35627236887`.
