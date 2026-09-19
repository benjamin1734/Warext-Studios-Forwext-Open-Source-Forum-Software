<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineStage;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AiModerationPipelineProcessor implements ContentPipelineProcessor
{
    public function __construct(
        private AiModerationService $moderation,
        private ?AiModerationForumPolicyRepository $forumPolicies = null,
        private AiModerationMetricsStore $metrics = new NullAiModerationMetricsStore(),
    ) {
    }

    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::AiModeration;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $forumNodeId = AiModerationPipelineAttributes::forumNodeId($context);
        $forumPolicy = $forumNodeId === null || $this->forumPolicies === null
            ? null
            : $this->forumPolicies->find($forumNodeId);
        $assessment = $this->moderation->evaluateForPolicy(
            new AiModerationRequest($context->contentType, $context->text),
            $forumPolicy,
        );
        $fingerprint = AiModerationFingerprint::forContent($context->contentType, $context->text);
        $this->metrics->record(new AiModerationMetric(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $fingerprint,
            $forumNodeId,
            $assessment->providerKey,
            $assessment->model,
            $assessment->promptVersion,
            $assessment->redacted,
            $assessment->usage,
            $at,
        ));

        return AiModerationPipelineAttributes::apply($context, $assessment)
            ->withAttribute('ai.content_fingerprint', $fingerprint);
    }
}
