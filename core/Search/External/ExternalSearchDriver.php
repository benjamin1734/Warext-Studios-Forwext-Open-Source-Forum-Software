<?php

declare(strict_types=1);

namespace Forwext\Core\Search\External;

use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchDriver;
use Forwext\Core\Search\SearchException;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;

final readonly class ExternalSearchDriver implements SearchDriver
{
    public function __construct(private ExternalSearchClient $client)
    {
    }

    public function upsert(SearchDocument $document): void
    {
        $this->client->upsert($document);
    }

    public function delete(string $documentType, string $documentId): bool
    {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        return $this->client->delete($documentType, $documentId);
    }

    public function search(SearchQuery $query): array
    {
        $hits = $this->client->search($query);
        $normalized = [];
        foreach ($hits as $hit) {
            if (!$hit instanceof SearchHit) {
                throw new SearchException('External search client returned an invalid hit type.');
            }
            $normalized[] = $hit;
            if (count($normalized) >= $query->limit) {
                break;
            }
        }

        return $normalized;
    }
}
