<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;

final readonly class PermissionAwareSearchService
{
    private const USE_PERMISSION = 'search.use';
    private const SCOPE_CHUNK = 128;

    /** @param iterable<SearchAccessScopeProvider> $scopeProviders */
    public function __construct(
        private SearchDriver $driver,
        private PermissionAuthorizer $authorizer,
        private iterable $scopeProviders,
    ) {
    }

    /**
     * Basic permission-aware native search. Advanced filters/pagination are added in 08.02.
     *
     * @param list<string> $documentTypes
     * @return list<SearchHit>
     */
    public function search(
        EntityId $userId,
        string $text,
        array $documentTypes = [],
        ?string $locale = null,
        int $limit = 20,
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new SearchException('Search result limit must be between 1 and 100.');
        }

        $decision = $this->authorizer->resolve($userId, PermissionKey::fromString(self::USE_PERMISSION));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }

        $scopeMap = [];
        foreach ($this->scopeProviders as $provider) {
            foreach ($provider->scopes($userId) as $scope) {
                SearchDocument::validateScope($scope);
                $scopeMap[$scope] = true;
            }
        }
        if ($scopeMap === []) {
            return [];
        }

        /** @var array<string, SearchHit> $merged */
        $merged = [];
        foreach (array_chunk(array_keys($scopeMap), self::SCOPE_CHUNK) as $scopes) {
            /** @var non-empty-list<string> $scopes */
            $query = new SearchQuery($text, $scopes, $documentTypes, $locale, $limit, 0);
            foreach ($this->driver->search($query) as $hit) {
                $key = $hit->documentType . "\0" . $hit->documentId;
                if (!isset($merged[$key]) || $hit->score > $merged[$key]->score) {
                    $merged[$key] = $hit;
                }
            }
        }

        $hits = array_values($merged);
        usort($hits, static function (SearchHit $left, SearchHit $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) return $score;
            $type = strcmp($left->documentType, $right->documentType);
            return $type !== 0 ? $type : strcmp($left->documentId, $right->documentId);
        });
        return array_slice($hits, 0, $limit);
    }
}
