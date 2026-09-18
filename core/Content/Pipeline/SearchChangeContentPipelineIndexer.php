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
        $this->changes->record($persisted->targetType, $persisted->targetId->value());
    }
}
