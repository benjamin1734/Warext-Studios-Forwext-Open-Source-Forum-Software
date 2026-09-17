<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

final readonly class SearchRebuildResult
{
    public function __construct(
        public string $documentType,
        public int $processed,
        public ?string $nextCursor,
    ) {
    }
}
