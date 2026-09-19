<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Freshness;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Freshness\ThreadFreshnessMaintenanceTasks;
use Forwext\Core\Forum\Freshness\ThreadFreshnessPolicy;
use Forwext\Core\Forum\Freshness\ThreadFreshnessRepository;
use Forwext\Core\Forum\Freshness\ThreadFreshnessReview;
use Forwext\Core\Forum\Freshness\ThreadFreshnessService;
use Forwext\Core\Forum\Freshness\ThreadFreshnessSnapshot;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ThreadFreshnessSystemTest extends TestCase
{
    public function testPolicyRejectsAutomaticActionBeforeStaleWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThreadFreshnessPolicy(
            $this->id('a'),
            true,
            30,
            20,
            10,
            null,
            null,
            null,
            24,
        );
    }

    public function testSnapshotExposesStaleAndArchiveBadges(): void
    {
        $now = $this->time();
        $stale = new ThreadFreshnessSnapshot(
            $this->id('1'),
            $this->id('2'),
            $this->id('3'),
            'Example',
            $now->modify('-40 days'),
            40,
            true,
            false,
            false,
            false,
            null,
            0,
            null,
            null,
            null,
            null,
            null,
        );
        self::assertSame('Güncelliğini yitirmiş', $stale->badge());

        $archived = new ThreadFreshnessSnapshot(
            $stale->threadId,
            $stale->forumNodeId,
            $stale->authorUserId,
            $stale->title,
            $stale->lastActivityAt,
            $stale->ageDays,
            true,
            true,
            true,
            false,
            null,
            0,
            null,
            null,
            null,
            $now,
            null,
        );
        self::assertSame('Arşivlenmiş', $archived->badge());
    }

    public function testMaintenanceAppliesBoundedLifecycleAndReindexesArchiveTree(): void
    {
        $now = $this->time();
        $threadId = $this->id('1');
        $forumId = $this->id('2');
        $postId = $this->id('3');

        $repository = new RecordingFreshnessRepository(
            new ThreadFreshnessPolicy($forumId, true, 30, null, 50, 80, 40, 60, 24),
            new ThreadFreshnessSnapshot(
                $threadId,
                $forumId,
                $this->id('4'),
                'Old thread',
                $now->modify('-100 days'),
                100,
                true,
                false,
                false,
                true,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
            ),
            $postId,
        );
        $search = new RecordingFreshnessSearchChanges();
        $service = new ThreadFreshnessService(
            $repository,
            new EmptyFreshnessNodes(),
            new PermissionAuthorizer(
                new PermissionEngine(new EmptyFreshnessPermissionRules()),
                new EmptyFreshnessAssignments(),
            ),
            $search,
        );

        $result = $service->maintain($now, 100);

        self::assertSame(1, $result->scanned);
        self::assertSame(1, $result->unfeatured);
        self::assertSame(1, $result->reviewsCreated);
        self::assertSame(1, $result->locked);
        self::assertSame(1, $result->archived);
        self::assertSame(0, $result->notified);
        self::assertTrue($repository->evaluated);
        self::assertSame(['thread:' . $threadId->value(), 'post:' . $postId->value()], $search->recorded);
    }

    public function testManualMaintenanceIsNodeScopedAndAudited(): void
    {
        $now = $this->time();
        $actor = $this->id('1');
        $forumId = $this->id('2');
        $threadId = $this->id('3');
        $postId = $this->id('5');

        $allowedRepository = new RecordingFreshnessRepository(
            new ThreadFreshnessPolicy($forumId, true, 30, null, 50, 80, 40, 60, 24),
            new ThreadFreshnessSnapshot(
                $threadId,
                $forumId,
                $this->id('4'),
                'Old thread',
                $now->modify('-100 days'),
                100,
                true,
                false,
                false,
                true,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
            ),
            $postId,
        );
        $audit = new FreshnessRecordingAudit();
        $allowed = new ThreadFreshnessService(
            $allowedRepository,
            new EmptyFreshnessNodes(),
            new PermissionAuthorizer(
                new PermissionEngine(new ScopedFreshnessReviewRules($actor, $forumId)),
                new ScopedFreshnessAssignments($actor),
            ),
            new RecordingFreshnessSearchChanges(),
            null,
            $audit,
        );

        $result = $allowed->maintainForActor(
            $actor,
            $now,
            100,
            AuditRequestId::fromString('req-freshness-maintenance'),
        );

        self::assertSame(1, $result->scanned);
        self::assertTrue($allowedRepository->evaluated);
        self::assertCount(1, $audit->events);
        self::assertSame('forum.thread.freshness.maintenance.manual', $audit->events[0]->action->value());
        self::assertSame('req-freshness-maintenance', $audit->events[0]->requestId->value());

        $deniedRepository = new RecordingFreshnessRepository(
            new ThreadFreshnessPolicy($forumId, true, 30, null, 50, 80, 40, 60, 24),
            new ThreadFreshnessSnapshot(
                $threadId,
                $forumId,
                $this->id('4'),
                'Old thread',
                $now->modify('-100 days'),
                100,
                true,
                false,
                false,
                true,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
            ),
            $postId,
        );
        $denied = new ThreadFreshnessService(
            $deniedRepository,
            new EmptyFreshnessNodes(),
            new PermissionAuthorizer(
                new PermissionEngine(new EmptyFreshnessPermissionRules()),
                new EmptyFreshnessAssignments(),
            ),
            new RecordingFreshnessSearchChanges(),
            null,
            new FreshnessRecordingAudit(),
        );

        $deniedResult = $denied->maintainForActor($actor, $now, 100);
        self::assertSame(0, $deniedResult->scanned);
        self::assertFalse($deniedRepository->evaluated);
    }

    public function testMaintenanceTaskUsesExistingMaintenanceQueueContract(): void
    {
        $registry = new SchedulerRegistry();
        ThreadFreshnessMaintenanceTasks::register($registry);

        $tasks = $registry->all();
        self::assertCount(1, $tasks);
        self::assertSame('thread.freshness.maintain', $tasks[0]->name);
        self::assertSame('thread.freshness.maintain', $tasks[0]->jobType);
        self::assertSame('maintenance', $tasks[0]->queue->value());
        self::assertSame('{"limit":100}', $tasks[0]->payload);
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 08:30:00', new DateTimeZone('UTC'));
    }
}

final class RecordingFreshnessRepository implements ThreadFreshnessRepository
{
    public bool $evaluated = false;

    public function __construct(
        private ThreadFreshnessPolicy $storedPolicy,
        private ThreadFreshnessSnapshot $storedSnapshot,
        private EntityId $postId,
    ) {
    }

    public function policy(EntityId $forumNodeId): ?ThreadFreshnessPolicy
    {
        return $forumNodeId->equals($this->storedPolicy->forumNodeId) ? $this->storedPolicy : null;
    }

    public function savePolicy(ThreadFreshnessPolicy $policy, DateTimeImmutable $at): void
    {
        $this->storedPolicy = $policy;
    }

    public function snapshot(EntityId $threadId, DateTimeImmutable $now): ?ThreadFreshnessSnapshot
    {
        return $threadId->equals($this->storedSnapshot->threadId) ? $this->storedSnapshot : null;
    }

    public function maintenanceThreadIds(DateTimeImmutable $now, int $limit = 100): array
    {
        return [$this->storedSnapshot->threadId];
    }

    public function markEvaluated(EntityId $threadId, DateTimeImmutable $at): void
    {
        $this->evaluated = true;
    }

    public function markNotified(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function autoLock(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function autoArchive(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function autoUnfeature(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function requestReview(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function pendingReviews(DateTimeImmutable $now, int $limit = 100): array
    {
        return [];
    }

    public function resolveReview(EntityId $threadId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): bool
    {
        return true;
    }

    public function renew(EntityId $threadId, EntityId $actorUserId, DateTimeImmutable $at, bool $reopenArchived): void
    {
    }

    public function postIds(EntityId $threadId, int $limit = 10000): array
    {
        return [$this->postId];
    }
}

final class RecordingFreshnessSearchChanges implements SearchIndexChangeStore
{
    /** @var list<string> */
    public array $recorded = [];

    public function record(string $documentType, string $documentId): void
    {
        $this->recorded[] = $documentType . ':' . $documentId;
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        return [];
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return true;
    }

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool {
        return true;
    }
}

final class EmptyFreshnessNodes implements ForumNodeRepository
{
    public function find(EntityId $nodeId): ?ForumNode { return null; }
    public function findBySlug(ForumNodeSlug $slug): ?ForumNode { return null; }
    public function all(): array { return []; }
    public function save(ForumNode $node): void {}
    public function delete(EntityId $nodeId): void {}
}

final class EmptyFreshnessPermissionRules implements PermissionRuleRepository
{
    public function definition(PermissionKey $key): ?\Forwext\Core\Domain\Access\Permission\PermissionDefinition
    {
        return null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return [];
    }
}

final class EmptyFreshnessAssignments implements UserAccessAssignmentProvider
{
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return null;
    }
}


final class FreshnessRecordingAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}

final readonly class ScopedFreshnessReviewRules implements PermissionRuleRepository
{
    public function __construct(private EntityId $actor, private EntityId $forumId)
    {
    }

    public function definition(PermissionKey $key): ?\Forwext\Core\Domain\Access\Permission\PermissionDefinition
    {
        return $key->value() === 'forum.thread.freshness.review'
            ? new \Forwext\Core\Domain\Access\Permission\PermissionDefinition(
                $key,
                \Forwext\Core\Domain\Access\Permission\PermissionValueType::Flag,
            )
            : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($key->value() !== 'forum.thread.freshness.review'
            || !$assignment->userId()->equals($this->actor)
            || $nodeId === null
            || !$nodeId->equals($this->forumId)
        ) {
            return [];
        }

        return [new \Forwext\Core\Domain\Access\Permission\PermissionRule(
            \Forwext\Core\Domain\Access\Permission\PermissionSubjectType::User,
            $this->actor,
            \Forwext\Core\Domain\Access\Permission\PermissionEffect::Allow,
            $this->forumId,
        )];
    }
}

final readonly class ScopedFreshnessAssignments implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($this->actor, EntityId::fromString(str_repeat('9', 32)))
            : null;
    }
}
