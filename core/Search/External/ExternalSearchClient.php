<?php

declare(strict_types=1);

namespace Forwext\Core\Search\External;

use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;

interface ExternalSearchClient
{
    public function upsert(SearchDocument $document): void;

    public function delete(string $documentType, string $documentId): bool;

    /** @return list<SearchHit> */
    public function search(SearchQuery $query): array;
}
