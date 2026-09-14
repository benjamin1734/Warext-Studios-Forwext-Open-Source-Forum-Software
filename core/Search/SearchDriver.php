<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

interface SearchDriver
{
    public function upsert(SearchDocument $document): void;

    public function delete(string $documentType, string $documentId): bool;

    /** @return list<SearchHit> */
    public function search(SearchQuery $query): array;
}
