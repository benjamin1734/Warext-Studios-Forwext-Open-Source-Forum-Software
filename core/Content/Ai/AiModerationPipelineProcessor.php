<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineStage;

final readonly class AiModerationPipelineProcessor implements ContentPipelineProcessor
{
    public function __construct(private AiModerationService $moderation)
    {
    }

    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::AiModeration;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $assessment = $this->moderation->evaluate(new AiModerationRequest(
            $context->contentType,
            $context->text,
        ));
        return AiModerationPipelineAttributes::apply($context, $assessment)
            ->withAttribute(
                'ai.content_fingerprint',
                AiModerationFingerprint::forContent($context->contentType, $context->text),
            );
    }
}
