<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use Forwext\Core\Search\Saved\SavedSearchQueryRegistry;

final readonly class PermissionAwareSearchService
{
    private const USE_PERMISSION = 'search.use';
    private const SCOPE_CHUNK = 128;
    private const MAX_SERVICE_OFFSET = 1000;
    private SavedSearchQueryRegistry $savedQueries;

    /** @param iterable<SearchAccessScopeProvider> $scopeProviders */
    public function __construct(
        private SearchDriver $driver,
        private PermissionAuthorizer $authorizer,
        private iterable $scopeProviders,
        ?SavedSearchQueryRegistry $savedQueries = null,
    ) {
        $this->savedQueries = $savedQueries ?? new SavedSearchQueryRegistry();
    }

    /**
     * @param list<string> $documentTypes
     * @return list<SearchHit>
     */
    public function search(
        EntityId $userId,
        string $text,
        array $documentTypes = [],
        ?string $locale = null,
        int $limit = 20,
        int $offset = 0,
        ?AdvancedSearchFilters $filters = null,
    ): array {
        if ($limit < 1 || $limit > 100) throw new SearchException('Search result limit must be between 1 and 100.');
        if ($offset < 0 || $offset > self::MAX_SERVICE_OFFSET) throw new SearchException('Search result offset must be between 0 and 1000.');
        $this->assertCanSearch($userId);

        $scopeMap = [];
        foreach ($this->scopeProviders as $provider) {
            foreach ($provider->scopes($userId) as $scope) {
                SearchDocument::validateScope($scope);
                $scopeMap[$scope] = true;
            }
        }
        if ($scopeMap === []) return [];

        $wanted = $offset + $limit;
        /** @var array<string,SearchHit> $merged */
        $merged = [];
        foreach (array_chunk(array_keys($scopeMap), self::SCOPE_CHUNK) as $scopes) {
            /** @var non-empty-list<string> $scopes */
            $driverOffset = 0;
            while ($driverOffset < $wanted) {
                $batchLimit = min(100, $wanted - $driverOffset);
                $query = new SearchQuery($text, $scopes, $documentTypes, $locale, $batchLimit, $driverOffset, $filters);
                $batch = $this->driver->search($query);
                foreach ($batch as $hit) {
                    $key = $hit->documentType . "\0" . $hit->documentId;
                    if (!isset($merged[$key]) || $hit->score > $merged[$key]->score) $merged[$key] = $hit;
                }
                if (count($batch) < $batchLimit) break;
                $driverOffset += $batchLimit;
            }
        }

        $hits = array_values($merged);
        usort($hits, static function (SearchHit $left, SearchHit $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) return $score;
            $type = strcmp($left->documentType, $right->documentType);
            return $type !== 0 ? $type : strcmp($left->documentId, $right->documentId);
        });
        return array_slice($hits, $offset, $limit);
    }

    /** @return list<SearchHit> */
    public function searchSaved(EntityId $userId, string $key, string $text, int $limit = 20, int $offset = 0): array
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key) !== 1) throw new SearchException('Saved search key is invalid.');
        $extension = $this->savedQueries->find($key);
        if ($extension === null) throw new SearchException('Saved search is not registered.');
        $definition = $extension->query($userId);
        return $this->search(
            $userId, $text, $definition->documentTypes, $definition->locale,
            $limit, $offset, $definition->filters,
        );
    }

    /** @return list<string> */
    public function savedQueryKeys(): array { return $this->savedQueries->keys(); }

    private function assertCanSearch(EntityId $userId): void
    {
        $decision = $this->authorizer->resolve($userId, PermissionKey::fromString(self::USE_PERMISSION));
        if (!$decision->isAllowed()) throw new PermissionDeniedException($decision);
    }
}
