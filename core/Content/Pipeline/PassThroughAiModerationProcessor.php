<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;

final readonly class PassThroughAiModerationProcessor implements ContentPipelineProcessor
{
    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::AiModeration;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        return $context;
    }
}
