<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Saved;

use Forwext\Core\Search\AdvancedSearchFilters;
use Forwext\Core\Search\SearchDocument;
use InvalidArgumentException;

final readonly class SavedSearchQuery
{
    /** @var list<string> */ public array $documentTypes;

    /** @param list<string> $documentTypes */
    public function __construct(
        array $documentTypes = [],
        public ?string $locale = null,
        public AdvancedSearchFilters $filters = new AdvancedSearchFilters(),
    ) {
        if (count($documentTypes) > 32) throw new InvalidArgumentException('Saved search may filter at most 32 document types.');
        $map = [];
        foreach ($documentTypes as $type) {
            if (!is_string($type)) throw new InvalidArgumentException('Saved search document types must be strings.');
            SearchDocument::validateIdentifier($type, 'document type');
            $map[$type] = true;
        }
        if (count($map) !== count($documentTypes)) throw new InvalidArgumentException('Saved search document types must be unique.');
        if ($locale !== null && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('Saved search locale is invalid.');
        }
        $this->documentTypes = array_keys($map);
    }
}
