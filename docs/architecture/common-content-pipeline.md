# Common Content Pipeline

Roadmap sub-step **12.01** defines the first-party content-processing contract used by forum content before it becomes durable and searchable.

The canonical order is:

1. validation
2. spam
3. spellcheck
4. AI moderation
5. moderation policy
6. persist
7. notify
8. index

The order is represented by `ContentPipelineStage` and enforced by `ContentPipeline`; callers cannot register processors for persist, notify or index because those stages are owned by the engine.

## Pre-persist stages

Every pipeline instance must register exactly one processor for each pre-persist stage:

- `validation`
- `spam`
- `spellcheck`
- `ai_moderation`
- `moderation_policy`

Missing stages and duplicate stage registrations fail fast.

### Validation

`DefaultContentValidationProcessor` trims the content and rejects:

- empty input;
- content above its per-operation byte limit;
- invalid UTF-8;
- unsafe control characters.

Forum integrations keep their existing hard limits: thread titles use 200 bytes and post bodies use 100,000 bytes.

### Spam

`AbuseContentPipelineProcessor` adapts the existing first-party `AbuseEngine` to the common pipeline.

For thread/post content it:

- preserves authenticated actor identity;
- accepts validated request identity/IP/device fingerprints;
- derives the content fingerprint from normalized text;
- evaluates the existing abuse rules;
- rejects before persistence when policy returns `reject`;
- carries `review` into `ContentPipelineContext::requiresReview`;
- records review events after the real target id exists.

Rejected attempts continue to be recorded without a persisted target. Review events are finalized inside the persist transaction with the real `forum.thread` or `forum.post` target.

### Spellcheck

`PassThroughSpellcheckProcessor` establishes the stage contract without inventing a spellchecking implementation in 12.01.

Real Turkish-first spellchecking, editor highlights, dictionaries and provider extensibility belong to roadmap **12.04**. Replacing the pass-through processor does not change the pipeline order or forum persistence contract.

### AI moderation

`PassThroughAiModerationProcessor` establishes the AI moderation position in the pipeline while keeping 12.01 provider-neutral and network-free.

Provider abstraction, risk scores, allow/flag/queue/reject behavior, timeout fallback and human override belong to roadmap **12.02**.

### Moderation policy

`DefaultModerationPolicyProcessor` preserves the review requirement accumulated by forum settings, anti-abuse and future analysis processors.

Future policy layers may add per-forum decisions without moving persist ahead of moderation policy.

## Persist, notify and index transaction

After all pre-persist processors succeed, `ContentPipeline` opens one database transaction and executes:

1. persist callback;
2. stage-owned after-persist bookkeeping;
3. notification stage;
4. index stage.

If any operation fails, the transaction is rolled back.

Notification implementations used here must be durable/transaction-safe work such as inserting notification or delivery-queue records; they must not perform irreversible outbound network delivery inside the database transaction.

The default notifier is intentionally empty until a concrete content type has a first-party notification contract.

## Search indexing

`SearchChangeContentPipelineIndexer` writes to the existing `SearchIndexChangeStore`.

First-party target names map as follows:

- `forum.thread` → search document type `thread`
- `forum.post` → search document type `post`

This keeps persistence and the durable search-change enqueue in the same transaction. The existing search drain worker remains responsible for actual index mutation.

## Forum integration

12.01 integrates the pipeline into:

- thread creation;
- first-post creation;
- replies;
- post edits.

Existing permission checks, forum settings, lock/reply restrictions and domain value objects still execute as before.

For edits, a review decision can return previously visible content to the pending moderation state before persistence.

Legacy constructor behavior remains compatible when no pipeline is supplied, allowing existing code/tests and incremental runtime wiring to continue safely while first-party callers adopt `ForumContentPipelineFactory`.

## No migration or new permission

12.01 adds no database table and no permission key.

It reuses:

- the existing abuse-rule/event persistence;
- forum permission checks;
- post/thread moderation states;
- the existing search index change queue.

## Roadmap boundary

This sub-step defines and integrates the common pipeline contract only.

Next roadmap step:

**12.02 — AI içerik denetimi**

That step owns provider abstraction, model decisions, risk scores, fallback behavior and human override. Secret/privacy/cost/prompt concerns remain 12.03, while the actual spellcheck system remains 12.04.
