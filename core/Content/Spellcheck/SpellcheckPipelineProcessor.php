<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineStage;

final readonly class SpellcheckPipelineProcessor implements ContentPipelineProcessor
{
    public function __construct(
        private SpellcheckService $spellcheck,
        private string $language = 'tr-tr',
    ) {
    }

    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::Spellcheck;
    }

    public function process(ContentPipelineContext $context, DateTimeImmutable $at): ContentPipelineContext
    {
        $result = $this->spellcheck->checkIfAllowed($context->actorUserId, $context->text, $this->language);
        if ($result === null) {
            return $context
                ->withAttribute('spellcheck.enabled', false)
                ->withAttribute('spellcheck.issue_count', 0);
        }

        return $context
            ->withAttribute('spellcheck.enabled', true)
            ->withAttribute('spellcheck.provider', $result->providerKey)
            ->withAttribute('spellcheck.language', $result->language)
            ->withAttribute('spellcheck.issue_count', count($result->issues));
    }
}
