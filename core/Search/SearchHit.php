<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use InvalidArgumentException;

final readonly class SearchHit
{
    public function __construct(
        public string $documentType,
        public string $documentId,
        public float $score,
        public ?string $title = null,
    ) {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        if (!is_finite($score) || $score < 0.0) throw new InvalidArgumentException('Search hit score must be a finite non-negative value.');
        if ($title !== null && ($title === '' || strlen($title) > 1000)) throw new InvalidArgumentException('Search hit title is invalid.');
    }
}
