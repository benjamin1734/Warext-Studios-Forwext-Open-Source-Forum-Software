<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Manager;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Content\Manager\ContentManagerAccessDeniedException;
use Forwext\Core\Content\Manager\ContentManagerAction;
use Forwext\Core\Content\Manager\ContentManagerContentType;
use Forwext\Core\Content\Manager\ContentManagerFilter;
use Forwext\Core\Content\Manager\ContentManagerItem;
use Forwext\Core\Content\Manager\ContentManagerOperation;
use Forwext\Core\Content\Manager\ContentManagerOperationItem;
use Forwext\Core\Content\Manager\ContentManagerOperationRepository;
use Forwext\Core\Content\Manager\ContentManagerOperationStatus;
use Forwext\Core\Content\Manager\ContentManagerRepository;
use Forwext\Core\Content\Manager\ContentManagerService;
use Forwext\Core\Content\Manager\ContentManagerTarget;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Queue\JobId;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use Forwext\Core\Queue\QueueReservation;
use PHPUnit\Framework\TestCase;

final class ContentManagerServiceTest extends TestCase
{
    public function testDryRunAndEnqueueFreezeMatchingTargetsAndQueueWork(): void
    {
        $actor = $this->id('1');
        $targetUser = $this->id('2');
        $forum = $this->id('3');
        $content = new MemoryContentManagerRepository([
            new ContentManagerTarget(ContentManagerContentType::Thread, $this->id('a'), $forum, 'visible', false),
            new ContentManagerTarget(ContentManagerContentType::Post, $this->id('b'), $forum, 'pending', false),
        ]);
        $operations = new MemoryContentManagerOperations();
        $queue = new MemoryContentManagerQueue();
        $audit = new ContentManagerRecordingAudit();
        $service = new ContentManagerService(
            $content,
            $operations,
            new EmptyContentManagerNodes(),
            $this->authorizer($actor, true),
            $queue,
            $audit,
        );
        $filter = new ContentManagerFilter($targetUser);

        $preview = $service->preview($actor, $filter, ContentManagerAction::Delete);
        self::assertSame(2, $preview->total());
        self::assertSame(['thread'=>1,'post'=>1], $preview->countsByType());
        self::assertFalse($preview->truncated);

        $operation = $service->enqueue(
            $actor,
            $filter,
            ContentManagerAction::Delete,
            null,
            $this->time(),
            AuditRequestId::fromString('req-content-manager'),
        );

        self::assertSame(2, $operation->totalCount);
        self::assertSame(ContentManagerOperationStatus::Queued, $operation->status);
        self::assertCount(2, $operations->frozenTargets);
        self::assertSame('content.manager.execute', $queue->lastType);
        self::assertStringContainsString($operation->operationId->value(), $queue->lastPayload ?? '');
        self::assertCount(1, $audit->events);
        self::assertSame('content.manager.enqueue', $audit->events[0]->action->value());
        self::assertSame('req-content-manager', $audit->events[0]->requestId->value());
    }

    public function testExecutePermissionIsRequiredEvenWhenAccessPermissionExists(): void
    {
        $actor = $this->id('1');
        $service = new ContentManagerService(
            new MemoryContentManagerRepository([]),
            new MemoryContentManagerOperations(),
            new EmptyContentManagerNodes(),
            $this->authorizer($actor, false),
            new MemoryContentManagerQueue(),
        );

        $this->expectException(ContentManagerAccessDeniedException::class);
        $service->preview(
            $actor,
            new ContentManagerFilter($this->id('2')),
            ContentManagerAction::Delete,
        );
    }

    public function testMoveRequiresThreadOnlyFilterAndRealForum(): void
    {
        $actor = $this->id('1');
        $targetForum = $this->id('4');
        $service = new ContentManagerService(
            new MemoryContentManagerRepository([]),
            new MemoryContentManagerOperations(),
            new SingleContentManagerForumNodes($targetForum),
            $this->authorizer($actor, true),
            new MemoryContentManagerQueue(),
        );

        $this->expectException(\Forwext\Core\Content\Manager\ContentManagerOperationException::class);
        $service->preview(
            $actor,
            new ContentManagerFilter($this->id('2')),
            ContentManagerAction::Move,
            $targetForum,
        );
    }

    private function authorizer(EntityId $actor, bool $execute): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new ContentManagerPermissionRules($actor, $execute)),
            new ContentManagerAssignments($actor),
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 09:15:00', new DateTimeZone('UTC'));
    }
}

final class MemoryContentManagerRepository implements ContentManagerRepository
{
    /** @param list<ContentManagerTarget> $targets */
    public function __construct(private array $targets)
    {
    }

    public function search(ContentManagerFilter $filter, int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    public function targets(ContentManagerFilter $filter, int $limit = 501): array
    {
        return array_slice($this->targets, 0, $limit);
    }

    public function current(ContentManagerContentType $type, EntityId $id): ?ContentManagerTarget
    {
        foreach ($this->targets as $target) {
            if ($target->type === $type && $target->id->equals($id)) return $target;
        }
        return null;
    }

    public function source(ContentManagerContentType $type, EntityId $id): ?string
    {
        return 'content';
    }

    public function postIdsForThread(EntityId $threadId, int $limit = 10000): array
    {
        return [];
    }
}

final class MemoryContentManagerOperations implements ContentManagerOperationRepository
{
    /** @var list<ContentManagerTarget> */
    public array $frozenTargets = [];
    private ?ContentManagerOperation $operation = null;

    public function create(
        EntityId $operationId,
        EntityId $actorUserId,
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId,
        array $targets,
        DateTimeImmutable $at,
    ): void {
        $this->frozenTargets = $targets;
        $this->operation = new ContentManagerOperation(
            $operationId,
            $actorUserId,
            $filter->targetUserId,
            $action,
            $filter->contentType,
            $targetForumNodeId,
            ContentManagerOperationStatus::Queued,
            count($targets),
            0,
            0,
            0,
            0,
            $at,
            null,
            null,
        );
    }

    public function find(EntityId $operationId): ?ContentManagerOperation
    {
        return $this->operation !== null && $this->operation->operationId->equals($operationId)
            ? $this->operation : null;
    }

    public function recentForActor(EntityId $actorUserId, int $limit = 20): array { return []; }
    public function items(EntityId $operationId, int $limit = 200): array { return []; }
    public function claim(EntityId $operationId, int $limit, DateTimeImmutable $at): array { return []; }

    public function completeItem(
        EntityId $operationId,
        ContentManagerContentType $type,
        EntityId $contentId,
        \Forwext\Core\Content\Manager\ContentManagerItemStatus $status,
        ?string $failureCode,
        DateTimeImmutable $at,
    ): void {
    }

    public function refreshProgress(EntityId $operationId, DateTimeImmutable $at): ContentManagerOperation
    {
        return $this->operation ?? throw new \RuntimeException('missing');
    }
}

final class MemoryContentManagerQueue implements QueueDriver
{
    public ?string $lastType = null;
    public ?string $lastPayload = null;

    public function push(
        QueueName $queue,
        string $type,
        string $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
    ): JobId {
        $this->lastType = $type;
        $this->lastPayload = $payload;
        return JobId::fromString(str_repeat('f', 32));
    }

    public function reserve(QueueName $queue, int $visibilityTimeoutSeconds = 60): ?QueueReservation { return null; }
    public function acknowledge(QueueReservation $reservation): void {}
    public function retry(QueueReservation $reservation, int $delaySeconds = 0): void {}
    public function fail(QueueReservation $reservation, string $failureCode): void {}
}

final class ContentManagerPermissionRules implements PermissionRuleRepository
{
    public function __construct(private EntityId $actor, private bool $execute)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        if (!in_array($key->value(), ['content_manager.access','content_manager.execute'], true)) return null;
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $allowed = $key->value() === 'content_manager.access' || $this->execute;
        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            $allowed ? PermissionEffect::Allow : PermissionEffect::Deny,
        )];
    }
}

final class ContentManagerAssignments implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!$userId->equals($this->actor)) return null;
        return new UserAccessAssignment($this->actor, EntityId::fromString(str_repeat('9', 32)));
    }
}

class EmptyContentManagerNodes implements ForumNodeRepository
{
    public function find(EntityId $nodeId): ?ForumNode { return null; }
    public function findBySlug(ForumNodeSlug $slug): ?ForumNode { return null; }
    public function all(): array { return []; }
    public function save(ForumNode $node): void {}
    public function delete(EntityId $nodeId): void {}
}

final class SingleContentManagerForumNodes extends EmptyContentManagerNodes
{
    public function __construct(private EntityId $forumId)
    {
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        if (!$nodeId->equals($this->forumId)) return null;
        return ForumNode::forum(
            $this->forumId,
            null,
            'Test Forum',
            ForumNodeSlug::fromString('test-forum'),
            new ForumSettings(),
        );
    }
}


final class ContentManagerRecordingAudit implements AuditRecorder
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
