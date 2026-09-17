# Notification / Alert Engine (07.05)

Forwext notifications are first-party infrastructure shared by forum, profile, moderation, support, marketplace and add-on modules. Producers register a stable notification type in `NotificationRegistry` and dispatch `NotificationRequest` objects rather than writing notification tables directly.

## Channels and preferences

The core channels are `in_app`, `email` and `push`. Each notification definition declares safe defaults, while a user's category/channel preference overrides the default. In-app visibility is stored independently from external delivery rows so an email-only or push-only preference cannot leak into the in-app inbox.

## Grouping and deduplication

`dedupeKey` provides producer-level idempotency (for example a post/user pair). `groupKey` collapses unread events of the same notification type into one alert and increments its occurrence count. These keys have separate responsibilities and are persisted separately so grouping does not weaken idempotency.

Database-backed dispatch serializes notification decisions per recipient inside a transaction by locking the recipient user row with `FOR UPDATE`. The dedupe lookup, unread-group lookup/update, notification insert, dedupe record and channel delivery enqueue therefore share one recipient-scoped critical section. This prevents concurrent events for the same recipient from creating duplicate dedupe records or parallel unread groups while avoiding a global notification lock.

## Delivery and graceful degradation

Email and push deliveries are persisted as retryable jobs. `NotificationDeliveryWorker` contains transport exceptions, stores only bounded machine-safe error codes and retries with exponential backoff for at most eight attempts. Missing external transports therefore cannot break thread/post/profile persistence or the native PHP frontend. A cron invocation can process the same database-backed delivery queue on minimum cPanel hosting; advanced deployments can call the same worker from dedicated workers.

## Security boundaries

- Inbox reads and read-state updates are always scoped to `recipient_user_id`; notification ids alone never authorize access.
- Account inbox and preference operations use the common permission engine via `notification.alert.view` and `notification.preference.manage`.
- Notification action targets accept only same-origin absolute paths (`/…`), rejecting full and protocol-relative URLs to avoid open redirects.
- Templates are deterministic placeholder substitution; they do not evaluate PHP, expressions or HTML. Presentation layers must render title/body as text unless a separately reviewed rich-content renderer is introduced.
- External-provider exception messages are not persisted, reducing secret/token leakage into logs or database diagnostics.
- Database dispatch rejects a missing/deleted recipient while holding the recipient lock, so notification writes cannot continue against a stale actor identity.

## Extension API

First-party modules and third-party add-ons can register notification definitions through `NotificationRegistry`. Duplicate type registration fails closed to make conflicts deterministic. Channel transports implement `NotificationChannelTransport`, allowing email/push providers to be swapped without coupling producers to provider SDKs.
