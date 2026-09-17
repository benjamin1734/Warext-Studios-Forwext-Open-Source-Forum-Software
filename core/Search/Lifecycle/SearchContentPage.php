<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use InvalidArgumentException;

final readonly class SearchContentPage
{
    /** @param list<string> $documentIds */
    public function __construct(
        public array $documentIds,
        public ?string $nextCursor,
    ) {
        if ($documentIds === [] && $nextCursor !== null) {
            throw new InvalidArgumentException('Empty search content pages cannot expose a continuation cursor.');
        }
        foreach ($documentIds as $id) {
            if (!is_string($id) || $id === '') {
                throw new InvalidArgumentException('Search content page ids must be non-empty strings.');
            }
        }
        if ($nextCursor !== null && $nextCursor !== $documentIds[array_key_last($documentIds)]) {
            throw new InvalidArgumentException('Search content page cursor must reference the last returned id.');
        }
    }
}
