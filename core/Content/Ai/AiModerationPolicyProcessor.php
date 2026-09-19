<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\ContentPipelineAfterPersistProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineRejectedException;
use Forwext\Core\Content\Pipeline\ContentPipelineStage;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use ValueError;

final readonly class AiModerationPolicyProcessor implements ContentPipelineProcessor, ContentPipelineAfterPersistProcessor
{
    public function __construct(
        private AiModerationPolicy $policy = new AiModerationPolicy(),
        private AiModerationOverrideRepository $overrides = new NullAiModerationOverrideRepository(),
        private AiModerationDecisionStore $decisions = new NullAiModerationDecisionStore(),
        private ?AiModerationForumPolicyRepository $forumPolicies = null,
    ) {
    }

    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::ModerationPolicy;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $assessment = AiModerationPipelineAttributes::assessment($context);
        $fingerprint = AiModerationPipelineAttributes::fingerprint($context);
        $override = $this->overrides->active($fingerprint, $at);
        $policy = $this->resolvedPolicy($context);
        $decision = $policy->decide($assessment, $override);

        $context = $context
            ->withAttribute('ai.content_fingerprint', $fingerprint)
            ->withAttribute('ai.action', $decision->action->value)
            ->withAttribute('ai.human_override', $decision->humanOverride)
            ->withAttribute('ai.override_reason', $decision->overrideReason);

        if ($decision->action === AiModerationAction::Reject) {
            $this->decisions->record($this->record($context, null, null, $at));
            throw new ContentPipelineRejectedException('Content was rejected by AI moderation policy.');
        }
        if ($decision->action === AiModerationAction::Queue) {
            return $context->requiringReview(true);
        }
        return $context;
    }

    public function afterPersist(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        $action = $this->action($context);
        if ($action === AiModerationAction::Reject) {
            return;
        }
        $this->decisions->record($this->record(
            $context,
            $persisted->targetType,
            $persisted->targetId,
            $at,
        ));
    }

    private function resolvedPolicy(ContentPipelineContext $context): AiModerationPolicy
    {
        $forumNodeId = AiModerationPipelineAttributes::forumNodeId($context);
        if ($forumNodeId === null || $this->forumPolicies === null) {
            return $this->policy;
        }
        $forum = $this->forumPolicies->find($forumNodeId);
        return $forum?->moderationPolicy() ?? $this->policy;
    }

    private function record(
        ContentPipelineContext $context,
        ?string $targetType,
        ?EntityId $targetId,
        DateTimeImmutable $at,
    ): AiModerationDecisionRecord {
        $assessment = AiModerationPipelineAttributes::assessment($context);
        $humanOverride = $context->attributes['ai.human_override'] ?? false;
        if (!is_bool($humanOverride)) {
            throw new InvalidArgumentException('AI moderation human override pipeline attribute is invalid.');
        }
        return new AiModerationDecisionRecord(
            EntityId::fromString(bin2hex(random_bytes(16))),
            AiModerationPipelineAttributes::fingerprint($context),
            $assessment->providerKey,
            $assessment->model,
            $assessment->riskScore,
            $this->action($context),
            $assessment->fallbackReason,
            $humanOverride,
            $targetType,
            $targetId,
            $at,
        );
    }

    private function action(ContentPipelineContext $context): AiModerationAction
    {
        $value = $context->attributes['ai.action'] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('AI moderation action pipeline attribute is invalid.');
        }
        try {
            return AiModerationAction::from($value);
        } catch (ValueError $exception) {
            throw new InvalidArgumentException('AI moderation action pipeline attribute is invalid.', previous: $exception);
        }
    }
}
