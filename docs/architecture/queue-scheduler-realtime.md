# Forwext Queue, Scheduler & Realtime Runtime Contract

Status: **Normative implementation baseline**  
Roadmap step: **03.06 — Queue/scheduler/realtime sürücüleri**

## Queue contract

Forwext background work uses `QueueDriver`. Job payloads are opaque binary-safe strings; callers choose their own stable encoding and job type. The core queue lifecycle is:

1. `push` with queue, type, payload, availability and max-attempt count;
2. `reserve` with a bounded visibility timeout;
3. worker executes the handler;
4. success calls `acknowledge`;
5. recoverable failure calls `retry` with optional delay;
6. terminal failure calls `fail`, moving the job to dead-letter storage.

Reservations carry random ownership tokens. Ack/retry/fail operations are accepted only for the current token, preventing a worker whose lease expired from mutating a job later reserved by another worker.

### Database queue

`DatabaseQueueDriver` is the minimum cPanel-safe default. It uses `FOR UPDATE SKIP LOCKED` inside an explicit transaction, increments attempts at reservation time and stores reservation expiry/token. Exhausted jobs whose reservation timed out are moved to `forwext_failed_jobs` in bounded batches before new reservations are selected.

### Redis queue

`RedisQueueDriver` uses Lua scripts for atomic enqueue/reserve/requeue/ack/fail transitions. Queue keys use a Redis Cluster hash tag (`{queue-name}`), keeping the per-queue script keys in one slot. Binary payloads are base64-wrapped inside JSON metadata.

Redis is optional. The minimum deployment does not require Redis or a long-running worker supervisor; cron-invoked bounded workers remain a supported execution strategy.

## Scheduler contract

Five-field cron expressions are evaluated in UTC. Supported syntax includes `*`, comma lists, ranges and `/step`. Day-of-month/day-of-week follow standard cron OR behavior when both are restricted.

`SchedulerRegistry` owns declared tasks. A task points to a queue job rather than executing arbitrary application logic directly.

`SchedulerDispatcher` obtains a per-task/per-minute claim before enqueueing. This prevents duplicate cron invocations in the same minute from duplicating scheduled jobs. Database and Redis claim stores are implemented:

- DB claims use a unique `(task_name, minute_bucket_utc)` key and random token;
- Redis claims use NX/PX leases and token-safe delete;
- if enqueue fails, the claim is released so a retry can dispatch;
- a successful claim remains until DB pruning or Redis TTL expiry.

The scheduler does not grant authorization. Scheduled job handlers use the same application-service permission/security rules appropriate to their system actor/context.

## Realtime contract

Realtime delivery has one message/cursor model with three transport modes:

- **Polling** — minimum/cPanel default;
- **SSE** — HTTP server-sent event delivery;
- **WebSocket** — optional gateway-backed push for advanced deployments.

`RealtimeMessage` contains validated channel/event names, opaque payload and UTC creation time. `RealtimeEnvelope` adds a monotonically increasing sequence cursor. Database-backed history allows clients to resume from `afterSequence` after reconnect.

SSE encodes payload bytes as base64 in the `data:` field. WebSocket transport persists the envelope before broadcasting through the gateway, so reconnect/poll fallback can recover messages by cursor.

Transport selection never changes channel authorization. A caller must authorize subscription/read/publication before invoking the transport; possession of a channel name or cursor grants no permission.

## Database schema

Core migration `20260914213000_queue_scheduler_realtime` creates:

- `forwext_jobs`;
- `forwext_failed_jobs`;
- `forwext_scheduler_claims`;
- `forwext_realtime_messages`.

The migration is versioned/idempotent and non-transactional because MySQL/MariaDB DDL is not assumed to roll back atomically. Verification requires all four tables.

## Failure and security behavior

- queue reservation tokens are CSPRNG values;
- stale workers cannot ack/retry/fail a newer reservation;
- queue failure codes are bounded machine identifiers and do not contain exception dumps/secrets;
- arbitrary SQL is not exposed to job payloads;
- scheduler claims are token-scoped;
- realtime payloads are not interpreted as authorization data;
- Redis Lua operations fail visibly instead of silently degrading consistency;
- queue/realtime database payload columns are binary-safe `LONGBLOB`.

## Operational defaults

- queue driver: `database`;
- default queue: `default`;
- visibility timeout: 60 seconds;
- default max attempts: 3;
- scheduler claim driver: `database`;
- scheduler timezone: UTC;
- realtime mode: polling;
- default poll limit: 100.

Advanced VDS deployments may select Redis queue/claims and WebSocket gateways without changing domain/application code.

## Acceptance status

03.06 is complete when DB/Redis queue lifecycle, retries/dead-letter behavior, cron parsing/registry, duplicate-safe scheduler dispatch, polling/SSE/WebSocket transport abstraction, DB runtime migration and behavioral tests exist as real code.
