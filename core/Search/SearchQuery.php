<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use InvalidArgumentException;

final readonly class SearchQuery
{
    public string $text;
    /** @var non-empty-list<string> */ public array $accessScopes;
    /** @var list<string> */ public array $documentTypes;
    public AdvancedSearchFilters $filters;

    /**
     * @param non-empty-list<string> $accessScopes
     * @param list<string> $documentTypes
     */
    public function __construct(
        string $text,
        array $accessScopes,
        array $documentTypes = [],
        public ?string $locale = null,
        public int $limit = 20,
        public int $offset = 0,
        ?AdvancedSearchFilters $filters = null,
    ) {
        $text = trim($text);
        if ($text === '' || strlen($text) > 500) throw new InvalidArgumentException('Search query must be between 1 and 500 bytes.');
        if ($accessScopes === [] || count($accessScopes) > 128) throw new InvalidArgumentException('Search query requires between 1 and 128 access scopes.');
        if (count($documentTypes) > 32) throw new InvalidArgumentException('Search query may filter at most 32 document types.');
        if ($locale !== null && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1) throw new InvalidArgumentException('Search query locale is invalid.');
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000) throw new InvalidArgumentException('Search pagination is outside the allowed range.');

        $scopeMap = [];
        foreach ($accessScopes as $scope) {
            if (!is_string($scope)) throw new InvalidArgumentException('Search query access scopes must be strings.');
            SearchDocument::validateScope($scope);
            $scopeMap[$scope] = true;
        }
        if (count($scopeMap) !== count($accessScopes)) throw new InvalidArgumentException('Search query access scopes must be unique.');

        $typeMap = [];
        foreach ($documentTypes as $type) {
            if (!is_string($type)) throw new InvalidArgumentException('Search query document types must be strings.');
            SearchDocument::validateIdentifier($type, 'document type');
            $typeMap[$type] = true;
        }
        if (count($typeMap) !== count($documentTypes)) throw new InvalidArgumentException('Search query document types must be unique.');

        $this->text = $text;
        /** @var non-empty-list<string> $scopes */ $scopes = array_keys($scopeMap);
        $this->accessScopes = $scopes;
        $this->documentTypes = array_keys($typeMap);
        $this->filters = $filters ?? new AdvancedSearchFilters();
    }
}
