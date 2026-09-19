# Optional Service Failure and Graceful Degradation

Roadmap step 12.08 defines and verifies the minimum-runtime failure boundary for Forwext. Optional infrastructure must improve capability or throughput without becoming a hidden requirement for ordinary forum use.

## Supported minimum profile

The cPanel-first production baseline remains:

- PHP 8.4+;
- MySQL/MariaDB;
- native PHP web frontend;
- file/database-backed minimum drivers;
- native database search;
- no Redis requirement;
- no long-running worker/Supervisor requirement;
- no external AI provider requirement;
- no external search service requirement.

`config/defaults.php` already selects the minimum-safe defaults: database queue, database scheduler claims, polling realtime and native search.

## AI degradation

When AI moderation is not configured, `ForumContentPipelineFactory` uses `PassThroughAiModerationProcessor` and the normal validation/spam/spellcheck/persist path continues.

When AI is configured but the remote provider times out or returns a provider error, `AiModerationService` returns an operational fallback assessment. `AiModerationPolicy` always maps an assessment carrying a fallback reason to `queue`, never `reject`, so content can be persisted for human review rather than being lost because an optional service failed.

12.08 additionally handles stale configuration: if a per-forum policy references a provider that is no longer registered, the assessment uses `provider_unavailable`; if it references a removed prompt version, it uses `configuration_error`. Both remain human-review fallbacks.

This distinction is intentional: a provider outage must not silently turn moderation off, but it also must not make the forum unavailable.

## Worker absence

Normal forum content persistence does not depend on a running background worker. The synchronous pipeline persists content and records durable search-index changes; background workers only drain pending asynchronous work.

Systems introduced in step 12 also have bounded cPanel-safe human fallbacks where applicable:

- content-manager operations can process the next bounded batch manually;
- freshness maintenance can run a bounded manual batch;
- search index lifecycle stores retryable changes durably.

If no worker is currently running, queued jobs and search lifecycle changes remain durable until a worker/cron/manual path processes them. The expected degradation is delayed asynchronous work, not loss of forum read/write availability.

## Search degradation

Native database search is the minimum deployment baseline and remains first-class.

`ResilientSearchDriver` supports advanced deployments that place an optional external search driver in front of a maintained fallback driver:

- queries try the optional primary and automatically fall back if it is unavailable;
- index upsert/delete is applied to the fallback first;
- if the optional primary write then fails, a `SearchException` is still raised so `SearchIndexLifecycleService` records its normal retry/backoff;
- the local fallback index therefore remains current while external synchronization remains retryable.

The native web runtime now goes through `ResilientSearchDriver` even when no external primary is configured, keeping one contract for minimum and advanced deployments.

## Failure matrix

| Failure | Forum read/write | Moderation/safety behavior | Recovery |
| --- | --- | --- | --- |
| AI disabled | Available | AI stage passes through | Configure AI later |
| AI timeout/provider error | Available | Persist as human-review queue | Provider recovery / human review |
| AI provider removed | Available | `provider_unavailable` review fallback | Restore/change policy |
| AI prompt removed | Available | `configuration_error` review fallback | Restore/change prompt policy |
| No long-running worker | Available | Async work delayed only | Cron/manual/worker drain |
| External search query failure | Available | Native fallback search | Primary recovery |
| External search index write failure | Available | Native fallback index updated; external sync marked retryable | Lifecycle retry/backoff |

## What does not degrade

MySQL/MariaDB is part of the minimum runtime, not an optional service. Database unavailability is therefore not converted into fake success. Likewise permission failures, invalid input and explicit moderation rejection remain authoritative failures; graceful degradation must never bypass authorization or safety policy.

## Regression coverage

`OptionalServiceGracefulDegradationTest` verifies:

1. the core content pipeline persists with AI disabled and no worker runtime object;
2. a missing AI provider persists into human review instead of failing the request;
3. a removed prompt version returns a configuration fallback assessment;
4. search queries use the fallback when the optional primary throws;
5. search-index lifecycle retries a failed primary write while the fallback index has already been updated.

The normal PHP 8.4/8.5 suite and MySQL 8.4/MariaDB 10.11 post-install web bootstrap remain mandatory on every completion commit.
