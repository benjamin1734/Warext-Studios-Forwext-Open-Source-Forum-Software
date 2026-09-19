# Thread Freshness Architecture

Roadmap step 12.06 adds per-forum topic freshness policy, author renewal, stale badges, automated lifecycle actions and a moderator-review queue.

## Policy model

`ThreadFreshnessPolicy` is stored per forum node. A policy may be disabled without deleting its configuration and defines:

- stale window in days;
- optional author-notification threshold;
- optional auto-unfeature threshold;
- optional auto-lock threshold;
- optional moderator-review threshold;
- optional auto-archive threshold;
- renewal cooldown in hours.

Auto-lock/archive/unfeature/review thresholds may not precede the stale window. Policy management is backend-authoritative through `forum.thread.freshness.manage_policy` and targets real forum nodes only.

## Freshness clock

Freshness does **not** use the thread aggregate's `updated_at_utc` as its authoritative clock because automated lifecycle mutations also change that timestamp. `forwext_thread_freshness_state.last_activity_at_utc` is the independent freshness clock.

Existing threads are backfilled from the latest available thread/post activity. New posts update the freshness state and clear notification/review-evaluation markers. Explicit renewal also advances the freshness clock.

## Badge and renewal

`ThreadFreshnessSnapshot` exposes a deterministic badge:

- `Güncelliğini yitirmiş` once the forum stale window is reached;
- `Arşivlenmiş` once the topic has been archived.

Authors may renew their own non-archived topic when `forum.thread.freshness.renew_own` is allowed and the cooldown has expired. Staff with `forum.thread.freshness.renew_any` may bypass the cooldown and reopen a topic that was auto-archived.

Only locks applied by freshness automation are undone during a staff reopen. A pre-existing manual moderator lock is never silently removed. Automatic unfeature is not automatically reversed.

## Automatic lifecycle

`ThreadFreshnessService::maintain()` evaluates bounded candidate batches. Depending on policy and age it can:

1. notify the author once per freshness cycle;
2. remove featured state;
3. create one moderator-review case for the stale cycle;
4. lock the thread;
5. archive the thread.

Archive is functional state, not a cosmetic tag. Archived threads are excluded from normal thread repository listings and native search documents, and direct post creation refuses archived thread rows. Archive/search transitions enqueue the thread and related posts through the normal search-index lifecycle.

Evaluation writes `last_evaluated_at_utc`; the candidate query does not repeatedly monopolize the same oldest topics within the same hour.

## Moderator review

`forwext_thread_freshness_reviews` stores durable pending/resolved review cases. Authorized reviewers can keep the current lifecycle state, renew/reopen the topic, or archive it.

A `keep`/`archive` decision remains associated with the current stale cycle and is not immediately recreated. A later explicit renewal resets the cycle so a future stale period may legitimately create another review.

## Notification behavior

`ThreadFreshnessNotifier` registers the first-party `forum.thread.freshness.stale` notification type. Dedupe keys include the thread id and freshness-activity epoch, so a renewed topic can receive one warning in a later stale cycle without duplicate alerts inside the same cycle.

## Queue and cPanel operation

`ThreadFreshnessMaintenanceTasks` declares `thread.freshness.maintain` every 15 minutes on the existing maintenance queue. `ThreadFreshnessMaintenanceJobHandler` runs bounded batches.

The native moderation surface also offers a bounded 100-topic maintenance action so minimum cPanel deployments remain operable without a long-running worker supervisor.

## Permissions

- `forum.thread.freshness.renew_own`: allowed to standard signed-in templates;
- `forum.thread.freshness.renew_any`: moderator/administrator by default;
- `forum.thread.freshness.review`: moderator/administrator by default;
- `forum.thread.freshness.manage_policy`: administrator by default.

All web mutations are CSRF-protected and authorization is re-evaluated server-side.

## Schema

Migration `20260919100000_thread_freshness_system` adds:

- `forwext_threads.archived` / `archived_at_utc`;
- `forwext_thread_freshness_policies`;
- `forwext_thread_freshness_state`;
- `forwext_thread_freshness_reviews`;
- freshness permissions/template defaults and supporting indexes.

Cross-system audit unification for freshness/AI/spellcheck/content-manager is intentionally completed by roadmap step 12.07.
