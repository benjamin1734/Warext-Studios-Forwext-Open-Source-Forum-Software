# AI Content Moderation Architecture

Forwext roadmap steps 12.02 and 12.03 add an optional, provider-neutral AI moderation layer to the canonical forum content pipeline. The basic forum remains operational when AI is not configured.

## Provider boundary

The core registry supports OpenAI moderation, Gemini, Anthropic, OpenRouter and an HTTPS-only custom provider. Providers return a normalized risk score, category scores and usage metadata. Timeout/provider failures are converted to a deterministic fallback assessment and are queued for human review by the default moderation policy.

Custom endpoints pass through the hardened endpoint policy and pinned HTTPS transport. Private/reserved network targets, unsafe ports, credentials in URLs and ambiguous request targets are rejected.

## Secrets and privacy

Provider credentials are not stored in normal configuration or database policy rows. `SecretStoreAiModerationCredentialStore` namespaces credentials under `ai.provider.<provider>.credential` and delegates to the existing encrypted secret store (AES-256-GCM, protected file permissions).

Before external AI calls, the default privacy redactor replaces common email addresses, IP addresses, Turkish mobile numbers and token-like API credentials. The original content fingerprint is calculated from the unredacted forum content so exact-content moderation overrides remain stable.

## Prompt versions

Prompt-based providers receive a registered `AiModerationPrompt`. The core prompt is versioned as `core.v1`; additional versions can be registered explicitly. The selected prompt version is stored with operational metrics so policy changes remain attributable.

## Per-forum policies

`AiModerationForumPolicy` can independently configure, per forum:

- enabled/disabled state;
- provider key;
- prompt version;
- sensitive-data redaction;
- flag, queue and reject thresholds;
- input/output token prices expressed as micros per one million tokens.

Thread and post pipeline contexts carry the real forum node id, allowing the AI stage and moderation-policy stage to resolve the same forum policy.

## Usage and cost metrics

Providers normalize available input/output token counts. `AiModerationCostPolicy` converts configured per-million token rates to integer micros. `forwext_ai_moderation_metrics` records provider, model, prompt version, redaction state, token counts, calculated cost, forum id and the original content fingerprint.

Providers that do not return usage counts safely record zero rather than fabricating token totals.

## Human review and feedback

Roadmap 12.02 exact-content human overrides continue to take precedence over automated risk decisions. Roadmap 12.03 adds persisted false-positive/false-negative feedback linked to the moderation decision. Feedback submission requires the existing `ai.manage` backend permission; cross-system audit unification remains scoped to roadmap 12.07.

## Graceful degradation

AI moderation remains optional. When no AI service is supplied to `ForumContentPipelineFactory`, the existing pass-through AI stage and default moderation policy are used. Core forum validation, anti-abuse, persistence, notification and search indexing do not depend on an external AI provider.
