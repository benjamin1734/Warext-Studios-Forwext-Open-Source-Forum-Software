# Performance and observability qualification

Roadmap step 20.07 adds a reproducible performance gate for the minimum Forwext deployment profile. It is intentionally built around PHP 8.4, MySQL 8.4 and a 128 MiB PHP memory ceiling so the qualification remains relevant to ordinary cPanel/shared-hosting installations rather than only the advanced Redis/VDS profile.

## Large seeded dataset

The qualification starts from the real clean migration chain and seeds one forum, 800 threads, 6,400 posts and 800 native search documents in a single database transaction. No fixture-only repository or in-memory database substitutes for this dataset.

The generated content uses the production forum/thread/post/search tables and preserves their foreign-key and FULLTEXT constraints.

## Forum, thread and post load

Production database repositories execute repeated forum listing, 50-thread pagination and post-page reads. The probe records p50, p95 and maximum latency for each surface across 25 iterations.

The budgets are deliberately broad enough to avoid runner-noise failures while still catching accidental unbounded scans or severe regressions. Combined load must remain below the CI ceiling.

## Native search load

The production NativeDatabaseSearchDriver runs against the real MySQL FULLTEXT index and access-scope table. A sparse benchmark token ensures the qualification verifies actual hits instead of timing an empty-result shortcut.

Search p50/p95/max latency is included in the emitted observability artifact.

## Slow query and N+1

A counting TransactionalQueryExecutor wraps the production repositories. The acceptance contract is structural as well as temporal:

- forum listing: one query,
- thread listing: one query,
- post page: two queries (count plus page),
- native search: one query.

A regression that adds per-row queries fails even if the small CI dataset still happens to be fast. Latency p95 ceilings provide a second guard against slow-query regressions.

## Cache and queue observability

The probe exercises the production database cache store, records write/read timing and reports the persisted cache-entry count.

It also pushes three real database queue jobs, records ready backlog, reserves and acknowledges one job, and verifies the backlog changes from three to two. Failed-job count is included in the JSON output.

## Memory and OPcache

CI runs PHP with memory_limit=128M and opcache.enable_cli=1. Peak process memory must stay below 100 MiB, leaving headroom beneath the configured limit.

The OPcache extension and enabled runtime state are mandatory for this qualification. Runtime PHP version, memory limit and peak memory are emitted in the artifact.

## Shared-hosting low-resource profile

The performance gate does not require Redis, Meilisearch, Docker, a worker daemon or Node.js at runtime. It validates the native database-backed cPanel profile that remains the minimum supported deployment.

The advanced deployment profile can exceed these throughput characteristics by using Redis, external search, workers and shared object storage, but it must not weaken the minimum-profile acceptance gates.

## Artifact

Every successful run uploads build/performance-observability.json. The artifact contains dataset dimensions, seed duration, query-count budgets, p50/p95/max latencies, cache metrics, queue backlog metrics, peak memory, OPcache state and PHP version.

This is a qualification smoke and regression guard, not a universal production capacity claim. Real installations must size CPU, storage, database buffers and worker concurrency for their traffic and content distribution.
