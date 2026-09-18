<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PassthroughContentPipelineProcessor implements ContentPipelineProcessor
{
    public function __construct(private ContentPipelineStage $pipelineStage)
    {
        if(!in_array($this->pipelineStage,[
            ContentPipelineStage::Spellcheck,
            ContentPipelineStage::AiModeration,
            ContentPipelineStage::ModerationPolicy,
        ],true)){
            throw new InvalidArgumentException('Pass-through processors are restricted to deferred content-analysis stages.');
        }
    }

    public function stage(): ContentPipelineStage
    {
        return $this->pipelineStage;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        return $context;
    }
}
