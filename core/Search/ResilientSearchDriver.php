<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use Throwable;

final readonly class ResilientSearchDriver implements SearchDriver
{
    public function __construct(
        private SearchDriver $fallback,
        private ?SearchDriver $primary = null,
    ) {
    }

    public function upsert(SearchDocument $document): void
    {
        $this->fallback->upsert($document);
        if ($this->primary === null) {
            return;
        }

        try {
            $this->primary->upsert($document);
        } catch (Throwable $exception) {
            throw new SearchException(
                'Optional primary search index is unavailable; fallback index was updated and synchronization must retry.',
                previous: $exception,
            );
        }
    }

    public function delete(string $documentType, string $documentId): bool
    {
        $fallbackDeleted = $this->fallback->delete($documentType, $documentId);
        if ($this->primary === null) {
            return $fallbackDeleted;
        }

        try {
            return $this->primary->delete($documentType, $documentId) || $fallbackDeleted;
        } catch (Throwable $exception) {
            throw new SearchException(
                'Optional primary search index is unavailable; fallback delete completed and synchronization must retry.',
                previous: $exception,
            );
        }
    }

    public function search(SearchQuery $query): array
    {
        if ($this->primary !== null) {
            try {
                return $this->primary->search($query);
            } catch (Throwable) {
                // The primary search service is optional. Query the maintained fallback index.
            }
        }

        return $this->fallback->search($query);
    }
}
