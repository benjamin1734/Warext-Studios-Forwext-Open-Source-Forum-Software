<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SearchDocument
{
    /** @var non-empty-list<string> */
    public array $accessScopes;
    /** @var array<string,list<string>> */
    public array $attributes;

    /**
     * @param non-empty-list<string> $accessScopes
     * @param array<string,list<string>> $attributes
     */
    public function __construct(
        public string $documentType,
        public string $documentId,
        public string $title,
        public string $body,
        array $accessScopes,
        public DateTimeImmutable $updatedAt,
        public ?string $locale = null,
        array $attributes = [],
    ) {
        self::validateIdentifier($documentType, 'document type');
        self::validateIdentifier($documentId, 'document id');
        if ($title === '' || strlen($title) > 1000) {
            throw new InvalidArgumentException('Search document title must be between 1 and 1000 bytes.');
        }
        if (strlen($body) > 2_000_000) {
            throw new InvalidArgumentException('Search document body exceeds the 2 MB indexing limit.');
        }
        if ($locale !== null && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('Search document locale is invalid.');
        }
        if ($accessScopes === [] || count($accessScopes) > 64) {
            throw new InvalidArgumentException('Search document requires between 1 and 64 access scopes.');
        }

        $normalized = [];
        foreach ($accessScopes as $scope) {
            if (!is_string($scope)) throw new InvalidArgumentException('Search document access scopes must be strings.');
            self::validateScope($scope);
            $normalized[$scope] = true;
        }
        if (count($normalized) !== count($accessScopes)) {
            throw new InvalidArgumentException('Search document access scopes must be unique.');
        }
        /** @var non-empty-list<string> $scopes */
        $scopes = array_keys($normalized);
        $this->accessScopes = $scopes;

        if (count($attributes) > 32) throw new InvalidArgumentException('Search document may contain at most 32 attribute keys.');
        $normalizedAttributes = [];
        $valueCount = 0;
        foreach ($attributes as $key => $values) {
            if (!is_string($key) || !is_array($values)) throw new InvalidArgumentException('Search document attributes are malformed.');
            SearchAttribute::validateKey($key);
            if (count($values) > 64) throw new InvalidArgumentException('Search document attribute contains too many values.');
            $valueMap = [];
            foreach ($values as $value) {
                if (!is_string($value)) throw new InvalidArgumentException('Search document attribute values must be strings.');
                SearchAttribute::validateValue($value);
                $valueMap[$value] = true;
                ++$valueCount;
            }
            if (count($valueMap) !== count($values)) throw new InvalidArgumentException('Search document attribute values must be unique.');
            if ($valueMap !== []) $normalizedAttributes[$key] = array_keys($valueMap);
        }
        if ($valueCount > 256) throw new InvalidArgumentException('Search document contains too many attribute values.');
        $this->attributes = $normalizedAttributes;
    }

    public function key(): string
    {
        return hash('sha256', $this->documentType . "\0" . $this->documentId);
    }

    public static function validateIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Search %s is invalid.', $label));
        }
    }

    public static function validateScope(string $scope): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Search access scope is invalid.');
        }
    }
}
