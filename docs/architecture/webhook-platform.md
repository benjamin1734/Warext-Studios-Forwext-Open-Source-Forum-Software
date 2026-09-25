# Webhook platform (19.03)

Roadmap step 19.03 provides the shared outbound webhook delivery platform for core systems and add-on outbound webhook definitions.

## Destination security

Webhook destinations are approved by `WebhookDestinationPolicy`.

Only credential-free HTTPS URLs on port 443 are accepted. Fragments, literal IP hosts, localhost-style names, reserved pseudo-TLDs and hosts resolving to private/reserved IP addresses are rejected.

The destination is re-approved on every delivery attempt rather than trusting an old DNS decision. `PinnedHttpsWebhookTransport` reuses the existing pinned HTTPS transport, preserving TLS certificate and SNI verification, resolved-public-IP pinning, bounded response sizes and no automatic redirect following. This prevents a stored public hostname from silently redirecting or rebinding delivery into an internal network.

## Signing and secret rotation

Each subscription has a versioned `whsec_` secret stored in the existing encrypted secret store. Database rows retain only version metadata.

The signing input is:

`v1.<unix_timestamp>.<delivery_id>.<exact_json_body>`

The HMAC-SHA256 result is emitted as `v<secret-version>=<hex-digest>` in `X-Forwext-Webhook-Signature`.

Delivery also includes:

- `X-Forwext-Webhook-Id`;
- `X-Forwext-Webhook-Event`;
- `X-Forwext-Webhook-Timestamp`;
- `X-Forwext-Webhook-Test`.

Rotation creates a new secret version while retaining the previous secret for a bounded grace interval. During grace, both signatures are emitted. A second rotation is refused until the active grace interval expires. Once a later rotation succeeds, an expired stale previous secret is deleted.

## Delivery lifecycle

`WebhookPlatformService` creates subscriptions, publishes events and queues targeted test deliveries.

`WebhookDeliveryJobHandler` re-validates destination safety, signs the exact stored body, sends the HTTPS request, records the attempt and transitions delivery state.

HTTP handling:

- 2xx: delivered;
- 408, 425, 429 and 5xx: retryable;
- other non-2xx 4xx: terminal.

Retry delay starts at 30 seconds and doubles per failed attempt, capped at 86400 seconds. Subscriptions support 1–20 total delivery attempts.

The existing queue abstraction is reused. The minimum cPanel profile uses `DatabaseQueueDriver`; advanced installations may use another queue driver without changing webhook domain code.

## Logs and test delivery

Every attempt records attempt number, result, HTTP status, safe error code, retryability, start/end timestamps and duration. Response bodies and signing secrets are not persisted in attempt logs.

Delivery summaries and their attempts can be read through the repository and permission-gated management service.

A test webhook creates an ordinary signed delivery with `test=true` and the `X-Forwext-Webhook-Test: 1` marker. It targets only the selected subscription.

## Permissions and add-ons

Management operations require the existing `webhook.manage` permission. Default starter templates grant it only to administrators.

`AddonWebhookPublisher` resolves existing `AddonWebhookDefinition` metadata. Only definitions declared `outbound` may publish into the shared platform. The add-on definition's event name is used for subscription matching.

## Runtime and cPanel

`WebApplicationFactory::createWebhookPlatform()` and `createWebhookWorker()` compose the production database, encrypted secret store, DNS resolver, queue and pinned transport.

`bin/webhook-worker.php` runs a bounded batch suitable for cron. The release package includes `bin/`, and that directory receives the same deny-all web-server guard as other private runtime directories.

No Redis, Node.js, Supervisor, Docker or permanently running daemon is required for the minimum deployment.
