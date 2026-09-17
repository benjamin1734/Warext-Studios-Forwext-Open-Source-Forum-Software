<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

final readonly class SearchIndexDrainResult
{
    public function __construct(
        public int $processed,
        public int $succeeded,
        public int $failed,
        public int $superseded,
    ) {
    }
}
