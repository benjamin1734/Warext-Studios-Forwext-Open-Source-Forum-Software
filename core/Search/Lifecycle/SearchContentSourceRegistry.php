<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final class SearchContentSourceRegistry
{
    /** @var array<string, SearchContentSource> */
    private array $sources = [];

    public function register(SearchContentSource $source): void
    {
        $type = $source->documentType();
        SearchDocument::validateIdentifier($type, 'document type');
        if (isset($this->sources[$type])) {
            throw new SearchException(sprintf('Search content type "%s" is already registered.', $type));
        }
        $this->sources[$type] = $source;
    }

    public function require(string $documentType): SearchContentSource
    {
        SearchDocument::validateIdentifier($documentType, 'document type');
        return $this->sources[$documentType]
            ?? throw new SearchException(sprintf('Search content type "%s" is not registered.', $documentType));
    }

    /** @return list<string> */
    public function types(): array
    {
        $types = array_keys($this->sources);
        sort($types, SORT_STRING);
        return $types;
    }
}
