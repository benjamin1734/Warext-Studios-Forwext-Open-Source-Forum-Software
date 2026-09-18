<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;

final readonly class DefaultContentValidationProcessor implements ContentPipelineProcessor
{
    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::Validation;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $text = trim($context->text);
        if ($text === '' || strlen($text) > $context->maxBytes
            || preg_match('//u', $text) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1
        ) {
            throw new ContentPipelineRejectedException('Content validation failed.');
        }

        return $context->withText($text);
    }
}
