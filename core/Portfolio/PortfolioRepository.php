<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use Forwext\Core\Domain\Entity\EntityId;

interface PortfolioRepository
{
    /** @return list<PortfolioCategory> */
    public function categories(bool $activeOnly = true): array;
    public function category(string $key): ?PortfolioCategory;
    public function saveCategory(PortfolioCategory $category): void;
    public function project(EntityId $projectId): ?PortfolioProject;
    /** @return list<PortfolioProject> */
    public function projects(?EntityId $ownerUserId = null, bool $publishedOnly = true, bool $featuredOnly = false, int $limit = 100): array;
    public function saveProject(PortfolioProject $project): void;
    /** @return list<PortfolioComment> */
    public function comments(EntityId $projectId, bool $visibleOnly = true, int $limit = 100): array;
    public function saveComment(PortfolioComment $comment): void;
    public function setReaction(EntityId $actorUserId, EntityId $projectId, string $reactionKey): void;
    public function removeReaction(EntityId $actorUserId, EntityId $projectId): void;
    public function reactionSummary(EntityId $projectId): PortfolioReactionSummary;
    public function recordHistory(EntityId $projectId, EntityId $actorUserId, string $action, ?string $fromState, ?string $toState): void;
}
