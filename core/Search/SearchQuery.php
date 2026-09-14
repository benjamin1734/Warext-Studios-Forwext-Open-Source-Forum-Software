<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use InvalidArgumentException;

final readonly class SearchQuery
{
    public string $text;
    /** @var non-empty-list<string> */
    public array $accessScopes;
    /** @var list<string> */
    public array $documentTypes;
    public ?string $locale;
    public int $limit;
    public int $offset;

    /**
     * @param non-empty-list<string> $accessScopes
     * @param list<string> $documentTypes
     */
    public function __construct(
        string $text,
        array $accessScopes,
        array $documentTypes = [],
        ?string $locale = null,
        int $limit = 20,
        int $offset = 0,
    ) {
        $text = trim($text);
        if ($text === '' || strlen($text) > 500) {
            throw new InvalidArgumentException('Search query must be between 1 and 500 bytes.');
        }
        if ($accessScopes === [] || count($accessScopes) > 128) {
            throw new InvalidArgumentException('Search query requires between 1 and 128 access scopes.');
        }
        if (count($documentTypes) > 32) {
            throw new InvalidArgumentException('Search query may filter at most 32 document types.');
        }
        if ($locale !== null && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('Search query locale is invalid.');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000) {
            throw new InvalidArgumentException('Search pagination is outside the allowed range.');
        }

        $scopeMap = [];
        foreach ($accessScopes as $scope) {
            SearchDocument::validateScope($scope);
            $scopeMap[$scope] = true;
        }
        if (count($scopeMap) !== count($accessScopes)) {
            throw new InvalidArgumentException('Search query access scopes must be unique.');
        }

        $typeMap = [];
        foreach ($documentTypes as $type) {
            SearchDocument::validateIdentifier($type, 'document type');
            $typeMap[$type] = true;
        }
        if (count($typeMap) !== count($documentTypes)) {
            throw new InvalidArgumentException('Search query document types must be unique.');
        }

        $this->text = $text;
        /** @var non-empty-list<string> $scopes */
        $scopes = array_keys($scopeMap);
        $this->accessScopes = $scopes;
        $this->documentTypes = array_keys($typeMap);
        $this->locale = $locale;
        $this->limit = $limit;
        $this->offset = $offset;
    }
}
