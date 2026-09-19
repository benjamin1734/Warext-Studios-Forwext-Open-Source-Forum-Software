<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateInterval;
use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;

final readonly class ThreadFreshnessService
{
    public function __construct(
        private ThreadFreshnessRepository $freshness,
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
        private SearchIndexChangeStore $searchChanges,
        private ?ThreadFreshnessNotifier $notifier = null,
    ) {
    }

    public function snapshot(EntityId $actorUserId, EntityId $threadId, DateTimeImmutable $now): ThreadFreshnessSnapshot
    {
        $snapshot = $this->freshness->snapshot($threadId, $now)
            ?? throw new ThreadFreshnessException('Thread freshness state is unavailable.');
        $this->require($actorUserId, 'forum.view', $snapshot->forumNodeId);
        return $snapshot;
    }

    public function policy(EntityId $actorUserId, EntityId $forumNodeId): ?ThreadFreshnessPolicy
    {
        $this->require($actorUserId, 'forum.thread.freshness.manage_policy', $forumNodeId);
        return $this->freshness->policy($forumNodeId);
    }

    public function savePolicy(
        EntityId $actorUserId,
        ThreadFreshnessPolicy $policy,
        DateTimeImmutable $at,
    ): void {
        $node = $this->nodes->find($policy->forumNodeId);
        if ($node === null || $node->type() !== ForumNodeType::Forum) {
            throw new ThreadFreshnessException('Freshness policy target must be an existing forum.');
        }
        $this->require($actorUserId, 'forum.thread.freshness.manage_policy', $policy->forumNodeId);
        $this->freshness->savePolicy($policy, $at);
    }

    public function renew(
        EntityId $actorUserId,
        EntityId $threadId,
        DateTimeImmutable $at,
    ): ThreadFreshnessSnapshot {
        $snapshot = $this->freshness->snapshot($threadId, $at)
            ?? throw new ThreadFreshnessException('Thread freshness state is unavailable.');
        $this->require($actorUserId, 'forum.view', $snapshot->forumNodeId);

        $isAuthor = $snapshot->authorUserId !== null && $snapshot->authorUserId->equals($actorUserId);
        $canRenewAny = $this->allows($actorUserId, 'forum.thread.freshness.renew_any', $snapshot->forumNodeId);
        if ($snapshot->archived && !$canRenewAny) {
            throw new ThreadFreshnessAccessDeniedException('Archived threads require staff renewal authority.');
        }
        if (!$canRenewAny) {
            if (!$isAuthor || !$this->allows($actorUserId, 'forum.thread.freshness.renew_own', $snapshot->forumNodeId)) {
                throw new ThreadFreshnessAccessDeniedException('Thread renewal permission is denied.');
            }
        }

        $policy = $this->freshness->policy($snapshot->forumNodeId);
        if ($policy === null || !$policy->enabled) {
            throw new ThreadFreshnessException('Thread freshness policy is not enabled for this forum.');
        }
        if ($snapshot->lastRenewedAt !== null && $policy->renewalCooldownHours > 0) {
            $next = $snapshot->lastRenewedAt->add(new DateInterval('PT' . $policy->renewalCooldownHours . 'H'));
            if ($at < $next && !$canRenewAny) {
                throw new ThreadFreshnessException('Thread renewal cooldown is still active.');
            }
        }

        $this->freshness->renew($threadId, $actorUserId, $at, $canRenewAny);
        $this->reindexThreadTree($threadId);
        return $this->freshness->snapshot($threadId, $at)
            ?? throw new ThreadFreshnessException('Renewed thread freshness state is unavailable.');
    }

    /** @return list<ThreadFreshnessReview> */
    public function pendingReviews(EntityId $actorUserId, DateTimeImmutable $now, int $limit = 100): array
    {
        $canReviewAny = false;
        foreach ($this->nodes->all() as $node) {
            if ($node->type() === ForumNodeType::Forum
                && $this->allows($actorUserId, 'forum.thread.freshness.review', $node->id())
            ) {
                $canReviewAny = true;
                break;
            }
        }
        if (!$canReviewAny) {
            throw new ThreadFreshnessAccessDeniedException('Thread freshness review permission is denied.');
        }

        return array_values(array_filter(
            $this->freshness->pendingReviews($now, $limit),
            fn (ThreadFreshnessReview $review): bool =>
                $this->allows($actorUserId, 'forum.thread.freshness.review', $review->forumNodeId),
        ));
    }

    public function resolveReview(
        EntityId $actorUserId,
        EntityId $threadId,
        string $resolution,
        DateTimeImmutable $at,
    ): void {
        $snapshot = $this->freshness->snapshot($threadId, $at)
            ?? throw new ThreadFreshnessException('Thread freshness state is unavailable.');
        $this->require($actorUserId, 'forum.thread.freshness.review', $snapshot->forumNodeId);

        if ($resolution === 'renew') {
            $this->freshness->renew($threadId, $actorUserId, $at, true);
            $this->reindexThreadTree($threadId);
        } elseif ($resolution === 'archive') {
            if ($this->freshness->autoArchive($threadId, $at)) {
                $this->reindexThreadTree($threadId);
            }
        } elseif ($resolution !== 'keep') {
            throw new ThreadFreshnessException('Freshness review resolution is invalid.');
        }

        $this->freshness->resolveReview($threadId, $actorUserId, $resolution, $at);
    }

    public function maintain(DateTimeImmutable $now, int $limit = 100): ThreadFreshnessMaintenanceResult
    {
        $scanned = $notified = $locked = $archived = $unfeatured = $reviews = 0;
        foreach ($this->freshness->maintenanceThreadIds($now, $limit) as $threadId) {
            $snapshot = $this->freshness->snapshot($threadId, $now);
            if ($snapshot === null) {
                continue;
            }
            $policy = $this->freshness->policy($snapshot->forumNodeId);
            if ($policy === null || !$policy->enabled) {
                $this->freshness->markEvaluated($threadId, $now);
                continue;
            }
            ++$scanned;

            if ($policy->notifyAfterDays !== null
                && $snapshot->ageDays >= $policy->notifyAfterDays
                && $snapshot->notifiedAt === null
                && $this->notifier !== null
            ) {
                $this->notifier->staleWarning($snapshot);
                if ($this->freshness->markNotified($threadId, $now)) {
                    ++$notified;
                }
            }
            if ($policy->autoUnfeatureAfterDays !== null
                && $snapshot->ageDays >= $policy->autoUnfeatureAfterDays
                && $snapshot->featured
                && $this->freshness->autoUnfeature($threadId, $now)
            ) {
                ++$unfeatured;
            }
            if ($policy->moderatorReviewAfterDays !== null
                && $snapshot->ageDays >= $policy->moderatorReviewAfterDays
                && $snapshot->reviewRequestedAt === null
                && $this->freshness->requestReview($threadId, $now)
            ) {
                ++$reviews;
            }
            if ($policy->autoLockAfterDays !== null
                && $snapshot->ageDays >= $policy->autoLockAfterDays
                && !$snapshot->locked
                && $this->freshness->autoLock($threadId, $now)
            ) {
                ++$locked;
            }
            if ($policy->autoArchiveAfterDays !== null
                && $snapshot->ageDays >= $policy->autoArchiveAfterDays
                && !$snapshot->archived
                && $this->freshness->autoArchive($threadId, $now)
            ) {
                ++$archived;
                $this->reindexThreadTree($threadId);
            }
            $this->freshness->markEvaluated($threadId, $now);
        }
        return new ThreadFreshnessMaintenanceResult($scanned,$notified,$locked,$archived,$unfeatured,$reviews);
    }

    private function reindexThreadTree(EntityId $threadId): void
    {
        $this->searchChanges->record('thread', $threadId->value());
        foreach ($this->freshness->postIds($threadId) as $postId) {
            $this->searchChanges->record('post', $postId->value());
        }
    }

    private function require(EntityId $actorUserId, string $permission, EntityId $forumNodeId): void
    {
        if (!$this->allows($actorUserId, $permission, $forumNodeId)) {
            throw new ThreadFreshnessAccessDeniedException('Thread freshness permission is denied.');
        }
    }

    private function allows(EntityId $actorUserId, string $permission, ?EntityId $forumNodeId): bool
    {
        return $this->authorizer->allows(
            $actorUserId,
            PermissionKey::fromString($permission),
            $forumNodeId,
        );
    }
}
