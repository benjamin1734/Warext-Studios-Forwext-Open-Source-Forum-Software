<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Search\SearchDocument;
use InvalidArgumentException;

final readonly class SearchIndexChange
{
    public function __construct(
        public string $documentType,
        public string $documentId,
        public int $revision,
        public int $attempts,
    ) {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        if ($revision < 1 || $attempts < 0 || $attempts > 20) {
            throw new InvalidArgumentException('Search index change metadata is invalid.');
        }
    }
}
