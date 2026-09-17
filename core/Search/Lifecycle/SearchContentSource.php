<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Search\SearchDocument;

interface SearchContentSource
{
    public function documentType(): string;

    public function document(string $documentId): ?SearchDocument;

    public function scan(?string $afterId, int $limit): SearchContentPage;
}
