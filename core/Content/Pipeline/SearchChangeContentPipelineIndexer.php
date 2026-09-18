<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;

final readonly class SearchChangeContentPipelineIndexer implements ContentPipelineIndexer
{
    public function __construct(private SearchIndexChangeStore $changes)
    {
    }

    public function index(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        $documentType = match ($persisted->targetType) {
            'forum.thread' => 'thread',
            'forum.post' => 'post',
            default => $persisted->targetType,
        };
        $this->changes->record($documentType, $persisted->targetId->value());
    }
}
