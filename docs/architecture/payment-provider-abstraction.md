# Payment Provider Abstraction (14.05)

## Purpose

Forwext core owns payment state, idempotency, persistence, permissions and order synchronization without embedding a commercial payment SDK into the core package.

Concrete providers are adapters implementing `PaymentProvider`. A provider may be first-party or supplied later by a module/add-on. The minimum cPanel runtime does not require a payment provider, worker, Node.js, Redis or WebSocket process.

When no provider is registered, Marketplace orders continue to exist and the payment initiation UI remains hidden.

## Provider registration

`PaymentProviderRegistry` rejects duplicate or malformed provider keys.

`WebApplicationFactory` accepts an optional registry while preserving the existing single-argument constructor:

```php
$providers = new PaymentProviderRegistry([
    new ExamplePaymentProvider(/* provider-specific secrets/client */),
]);

$application = (new WebApplicationFactory($projectRoot, $providers))->create($version);
```

The default Forwext bootstrap passes no registry and therefore creates an empty one. Add-on/module bootstrapping can inject providers without modifying payment-domain code.

## Provider contract

A provider implements:

- `key()`
- `capabilities()`
- `create(PaymentCreateRequest)`
- `verifyWebhook(PaymentWebhookRequest)`
- `cancel(PaymentCancelRequest)`
- `refund(PaymentRefundRequest)`

The adapter is responsible for provider-specific API calls, authentication, SDK/client use and cryptographic webhook verification.

Forwext passes an idempotency key to create/refund/cancel operations. Adapters must forward it to the provider's native idempotency mechanism when one exists, or implement an equivalent provider-side guarantee.

## Payment attempts

Each external payment operation begins with a durable `PaymentAttempt`.

Attempt states are:

- `pending`
- `requires_action`
- `authorized`
- `paid`
- `failed`
- `cancelled`
- `refunded`

Forwext stores the immutable order id, buyer, amount, currency, provider and idempotency key. Provider reference, HTTPS checkout URL and bounded provider error code are stored as the provider lifecycle becomes known.

The unique key `(order_id, provider_key, idempotency_key)` forms the persistence idempotency boundary.

## Single active attempt rule

Payment initiation locks the Marketplace order row before creating an attempt.

A different payment attempt cannot start while the order's active attempt is:

- pending;
- requires action; or
- authorized.

This avoids parallel checkout sessions and double-charge races.

Failed/cancelled attempts may be followed by a new attempt. Paid/refunded orders cannot start a new payment.

The same order/provider/idempotency key returns the existing attempt even after the order has advanced.

## External checkout handoff

`PaymentProviderResult` accepts checkout redirects only when they are valid HTTPS URLs without embedded credentials.

Native order payment initiation uses a CSRF-protected POST. A `requires_action` result is returned to the browser with a 303 redirect and `Referrer-Policy: no-referrer`.

Return and cancel paths supplied to the provider are same-origin paths only; protocol-relative paths, backslashes, control characters and external absolute return URLs are rejected.

## Webhooks

Webhook endpoint:

`POST /payments/webhooks/{providerKey}`

The route intentionally has no user session or CSRF requirement because provider webhooks are server-to-server requests.

Security boundary:

1. provider key must be registered;
2. raw request body and headers are passed to the adapter;
3. adapter must cryptographically verify the provider request in `verifyWebhook()`;
4. failed verification rejects the webhook before state mutation;
5. normalized amount/currency/provider reference are matched against the durable attempt;
6. attempt and order rows are locked before state application.

Webhook bodies are limited to 1 MiB by the payment request contract.

Forwext does **not** persist the raw webhook body. Persistence contains only:

- provider key;
- provider event id;
- attempt id;
- normalized state;
- SHA-256 payload hash;
- provider occurrence time;
- local receive time.

The primary key `(provider_key, event_id)` makes provider event replay idempotent.

## State ordering

Webhook state application is monotonic.

Examples:

- pending -> requires_action -> authorized -> paid is allowed;
- paid cannot be downgraded by a late failed/cancelled event;
- paid may advance to refunded;
- duplicate equal states are no-ops.

Provider creation and webhook delivery may race. Post-provider state application locks the attempt and refuses to overwrite a state that already advanced asynchronously.

## Marketplace order synchronization

The payment attempt amount, currency and buyer must exactly match the immutable order snapshot.

The order stores the current `payment_attempt_id` and `payment_provider` in bounded receipt metadata.

Normalized mappings include:

- authorized -> order payment `authorized`;
- paid -> order `confirmed`, payment `paid`;
- failed -> payment `failed`;
- cancelled -> payment `cancelled`;
- refunded -> payment `refunded`.

Payment-attempt cancellation does not cancel the Marketplace order itself. A buyer may retry payment with another attempt after a failed/cancelled attempt.

Delivery state is not advanced by payment code; digital/manual fulfillment belongs to 14.06.

## Refunds

14.05 intentionally implements a full-refund foundation.

A refund:

- requires `payment.refund`;
- requires a paid attempt with provider reference;
- uses its own idempotency key;
- records a separate durable refund entity;
- prevents another pending/succeeded full refund from starting;
- synchronizes a successful refund to attempt `refunded` and order payment `refunded`.

Partial refunds are not silently inferred from webhook events in this foundation. A provider event claiming a mismatched refund amount is rejected.

## Cancellation

Provider cancellation is permitted only while an attempt is pending, requires action or authorized.

The provider call happens outside the database transaction. State is locked and revalidated when its response returns. If a paid/refunded state arrived concurrently, Forwext does not downgrade it to cancelled and surfaces a reconciliation error instead.

## Permissions

Shared permission engine:

- `marketplace.purchase`: buyer initiation;
- `payment.manage`: view payment operations;
- `payment.refund`: perform provider refund/cancel operations.

Default templates are conservative. Only administrator receives `payment.manage` and `payment.refund` by default.

## Native PHP UI

The first-party PHP frontend includes:

- payment initiation on eligible buyer order details;
- provider redirect handling;
- payment-state result display;
- `/admin/payments` operations page;
- full refund and cancellable-attempt controls.

All human mutations are CSRF-protected and backend permissions remain authoritative.

## Persistence

Migration `20260919214000_payment_abstraction` creates:

- `forwext_payment_attempts`
- `forwext_payment_webhook_events`
- `forwext_payment_refunds`

It also updates Marketplace order history to permit a `NULL` actor for verified system/provider webhook transitions. Human actions continue to store their real actor user id.
