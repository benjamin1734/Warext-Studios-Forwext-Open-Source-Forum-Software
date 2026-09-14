<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SearchDocument
{
    /** @var non-empty-list<string> */
    public array $accessScopes;

    /**
     * @param non-empty-list<string> $accessScopes
     */
    public function __construct(
        public string $documentType,
        public string $documentId,
        public string $title,
        public string $body,
        array $accessScopes,
        public DateTimeImmutable $updatedAt,
        public ?string $locale = null,
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
            self::validateScope($scope);
            $normalized[$scope] = true;
        }
        if (count($normalized) !== count($accessScopes)) {
            throw new InvalidArgumentException('Search document access scopes must be unique.');
        }

        /** @var non-empty-list<string> $scopes */
        $scopes = array_keys($normalized);
        $this->accessScopes = $scopes;
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
