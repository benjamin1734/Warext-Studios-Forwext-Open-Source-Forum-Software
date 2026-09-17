<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class ThreadDiscoveryService
{
    private const USE_PERMISSION = 'search.use';
    private const FORUM_SCOPE_PREFIX = 'forum.node:';
    private const MAX_OFFSET = 1000;

    /** @param iterable<SearchAccessScopeProvider> $scopeProviders */
    public function __construct(
        private ThreadDiscoveryRepository $repository,
        private PermissionAuthorizer $authorizer,
        private iterable $scopeProviders,
    ) {
    }

    /** @return list<DiscoveryThread> */
    public function discover(
        EntityId $userId,
        DiscoveryMode $mode,
        int $limit = 20,
        int $offset = 0,
        ?DateTimeImmutable $now = null,
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new SearchException('Discovery result limit must be between 1 and 100.');
        }
        if ($offset < 0 || $offset > self::MAX_OFFSET) {
            throw new SearchException('Discovery result offset must be between 0 and 1000.');
        }

        $decision = $this->authorizer->resolve(
            $userId,
            PermissionKey::fromString(self::USE_PERMISSION),
        );
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }

        /** @var array<string, true> $forumIds */
        $forumIds = [];
        foreach ($this->scopeProviders as $provider) {
            foreach ($provider->scopes($userId) as $scope) {
                SearchDocument::validateScope($scope);
                if (!str_starts_with($scope, self::FORUM_SCOPE_PREFIX)) {
                    continue;
                }

                $forumId = substr($scope, strlen(self::FORUM_SCOPE_PREFIX));
                if ($forumId !== '') {
                    $forumIds[$forumId] = true;
                }
            }
        }

        if ($forumIds === []) {
            return [];
        }

        $ids = array_keys($forumIds);
        sort($ids, SORT_STRING);

        return $this->repository->discover(
            $userId,
            $ids,
            $mode,
            ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC')),
            $limit,
            $offset,
        );
    }
}
