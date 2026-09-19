<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;

final readonly class PortfolioService
{
    public const SEARCH_TYPE = 'portfolio.item';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private PortfolioRepository $portfolio,
        private PermissionAuthorizer $authorizer,
        private ContentPipeline $contentPipeline,
        private SearchIndexChangeStore $searchChanges,
    ) {
    }

    /** @return list<PortfolioCategory> */
    public function categories(): array
    {
        return $this->portfolio->categories();
    }

    /** @return list<PortfolioProject> */
    public function projects(
        ?EntityId $actor,
        ?EntityId $owner = null,
        bool $featuredOnly = false,
        int $limit = 100,
    ): array {
        if ($actor !== null) {
            $this->require($actor, 'portfolio.view');
        }
        return $this->portfolio->projects($owner, true, $featuredOnly, $limit);
    }

    public function project(EntityId $projectId, ?EntityId $actor): PortfolioProject
    {
        $project = $this->portfolio->project($projectId)
            ?? throw new InvalidArgumentException('Portfolio project was not found.');

        if ($project->state !== PortfolioState::Published) {
            if ($actor === null || !$this->canManage($actor, $project)) {
                throw new InvalidArgumentException('Portfolio project is unavailable.');
            }
        } elseif ($actor !== null) {
            $this->require($actor, 'portfolio.view');
        }

        return $project;
    }

    public function saveCategory(EntityId $actor, PortfolioCategory $category): void
    {
        $this->require($actor, 'portfolio.manage_all');
        $this->portfolio->saveCategory($category);
    }

    public function saveProject(EntityId $actor, PortfolioProject $candidate): PortfolioProject
    {
        $existing = $this->portfolio->project($candidate->projectId);
        if ($existing === null) {
            $this->require($actor, 'portfolio.create');
            if (!$candidate->ownerUserId->equals($actor)) {
                $this->require($actor, 'portfolio.manage_all');
            }
        } elseif (!$this->canManage($actor, $existing)) {
            $this->require($actor, 'portfolio.manage_all');
        }

        $category = $this->portfolio->category($candidate->categoryKey);
        if ($category === null || !$category->active) {
            throw new InvalidArgumentException('Portfolio category is unavailable.');
        }

        $staff = $this->allows($actor, 'portfolio.manage_all');
        $featured = $staff ? $candidate->featured : ($existing?->featured ?? false);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $context = $this->contentPipeline->preprocess(
            new ContentPipelineContext(
                $actor,
                self::SEARCH_TYPE,
                $candidate->title . "\n\n" . $candidate->summary . "\n\n" . $candidate->description,
                120000,
            ),
            $now,
        );

        $state = $candidate->state === PortfolioState::Draft
            ? PortfolioState::Draft
            : ($staff && !$context->requiresReview
                ? PortfolioState::Published
                : PortfolioState::Pending);

        $project = new PortfolioProject(
            $candidate->projectId,
            $candidate->ownerUserId,
            $candidate->categoryKey,
            $candidate->slug,
            $candidate->title,
            $candidate->summary,
            $candidate->description,
            $candidate->tags,
            $candidate->media,
            $state,
            $featured,
            $existing?->createdAt ?? $candidate->createdAt,
            $now,
        );

        $this->database->transaction(function () use ($project, $actor, $existing): void {
            $this->portfolio->saveProject($project);
            $this->searchChanges->record(self::SEARCH_TYPE, $project->projectId->value());
            $this->portfolio->recordHistory(
                $project->projectId,
                $actor,
                $existing === null ? 'project.create' : 'project.update',
                $existing?->state->value,
                $project->state->value,
            );
        });

        return $project;
    }

    /** @return list<PortfolioComment> */
    public function comments(EntityId $projectId, ?EntityId $actor, int $limit = 100): array
    {
        $this->project($projectId, $actor);
        return $this->portfolio->comments($projectId, true, $limit);
    }

    public function addComment(EntityId $actor, EntityId $projectId, string $body): PortfolioComment
    {
        $this->require($actor, 'portfolio.comment.create');
        $project = $this->project($projectId, $actor);
        if ($project->state !== PortfolioState::Published) {
            throw new InvalidArgumentException('Comments require a published portfolio project.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $context = $this->contentPipeline->preprocess(
            new ContentPipelineContext($actor, 'portfolio.comment', $body, 10000),
            $now,
        );
        $comment = new PortfolioComment(
            PortfolioComment::generateId(),
            $projectId,
            $actor,
            $body,
            $context->requiresReview ? PortfolioCommentState::Pending : PortfolioCommentState::Visible,
            $now,
        );

        $this->database->transaction(function () use ($comment, $projectId, $actor): void {
            $this->portfolio->saveComment($comment);
            $this->portfolio->recordHistory(
                $projectId,
                $actor,
                'comment.create',
                null,
                $comment->state->value,
            );
        });

        return $comment;
    }

    public function react(EntityId $actor, EntityId $projectId, string $reactionKey): PortfolioReactionSummary
    {
        $this->require($actor, 'portfolio.reaction.use');
        $project = $this->project($projectId, $actor);
        if ($project->ownerUserId->equals($actor)) {
            throw new InvalidArgumentException('Users cannot react to their own portfolio project.');
        }
        $this->portfolio->setReaction($actor, $projectId, $reactionKey);
        return $this->portfolio->reactionSummary($projectId);
    }

    public function removeReaction(EntityId $actor, EntityId $projectId): PortfolioReactionSummary
    {
        $this->require($actor, 'portfolio.reaction.use');
        $this->project($projectId, $actor);
        $this->portfolio->removeReaction($actor, $projectId);
        return $this->portfolio->reactionSummary($projectId);
    }

    public function reactionSummary(EntityId $projectId, ?EntityId $actor): PortfolioReactionSummary
    {
        $this->project($projectId, $actor);
        return $this->portfolio->reactionSummary($projectId);
    }

    public function canCreate(EntityId $actor): bool
    {
        return $this->allows($actor, 'portfolio.create');
    }

    public function canManageAll(EntityId $actor): bool
    {
        return $this->allows($actor, 'portfolio.manage_all');
    }

    public function canManageProject(EntityId $actor, PortfolioProject $project): bool
    {
        return $this->canManage($actor, $project);
    }

    public function decision(EntityId $actor, string $permission): \Forwext\Core\Domain\Access\Permission\PermissionDecision
    {
        return $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
    }

    private function canManage(EntityId $actor, PortfolioProject $project): bool
    {
        return $this->allows($actor, 'portfolio.manage_all')
            || ($project->ownerUserId->equals($actor) && $this->allows($actor, 'portfolio.manage_own'));
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }
}
