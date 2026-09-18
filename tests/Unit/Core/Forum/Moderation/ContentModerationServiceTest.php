<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\BulkPostAction;
use Forwext\Core\Forum\Moderation\BulkThreadAction;
use Forwext\Core\Forum\Moderation\ContentModerationRepository;
use Forwext\Core\Forum\Moderation\ContentModerationService;
use Forwext\Core\Forum\Moderation\ModerationAuditContext;
use Forwext\Core\Forum\Moderation\ModerationPermission;
use Forwext\Core\Forum\Moderation\ModerationPostRecord;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Forum\Moderation\ModerationThreadRecord;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadPermission;
use Forwext\Core\Forum\Thread\ThreadTitle;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ContentModerationServiceTest extends TestCase
{
    public function testMoveRequiresStructuralPermissionInSourceAndTargetForum(): void
    {
        [$actor, $forumA, $forumB, $thread] = $this->fixture();
        $repository = new ModerationServiceRepository([$thread]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA, $forumB]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => ['forum.view', ModerationPermission::MoveThread->value],
                $forumB->id()->value() => ['forum.view'],
            ]),
        );

        $this->expectException(PermissionDeniedException::class);
        try {
            $service->moveThread(
                $thread->threadId,
                $forumB->id(),
                ModerationReasonCode::fromString('reorganize'),
                ModerationRequestId::fromString('req-move-denied'),
                $this->time('2026-09-15 21:00:00.000000'),
            );
        } finally {
            self::assertSame([], $repository->calls);
        }
    }

    public function testMoveUsesActorBoundAuditContextWhenBothScopesAllowIt(): void
    {
        [$actor, $forumA, $forumB, $thread] = $this->fixture();
        $repository = new ModerationServiceRepository([$thread]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA, $forumB]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => ['forum.view', ModerationPermission::MoveThread->value],
                $forumB->id()->value() => ['forum.view', ModerationPermission::MoveThread->value],
            ]),
        );

        $service->moveThread(
            $thread->threadId,
            $forumB->id(),
            ModerationReasonCode::fromString('reorganize'),
            ModerationRequestId::fromString('req-move-ok'),
            $this->time('2026-09-15 21:00:00.000000'),
        );

        self::assertSame(['move'], $repository->calls);
        self::assertNotNull($repository->lastContext);
        self::assertSame($actor->value(), $repository->lastContext->actorUserId->value());
        self::assertSame('reorganize', $repository->lastContext->reasonCode->value());
        self::assertSame('req-move-ok', $repository->lastContext->requestId->value());
    }

    public function testBulkRequiresBulkPermissionAndUnderlyingActionPermission(): void
    {
        [$actor, $forumA, , $thread] = $this->fixture();
        $repository = new ModerationServiceRepository([$thread]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => ['forum.view', ModerationPermission::Bulk->value],
            ]),
        );

        $this->expectException(PermissionDeniedException::class);
        try {
            $service->bulkThreads(
                BulkThreadAction::Sticky,
                [$thread->threadId],
                ModerationReasonCode::fromString('bulk_review'),
                ModerationRequestId::fromString('req-bulk-denied'),
                $this->time('2026-09-15 21:05:00.000000'),
            );
        } finally {
            self::assertSame([], $repository->calls);
        }
    }

    public function testBulkRejectUsesModerateAndBulkPermissions(): void
    {
        [$actor, $forumA, , $thread] = $this->fixture();
        $repository = new ModerationServiceRepository([$thread]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => [
                    'forum.view',
                    ModerationPermission::Bulk->value,
                    ThreadPermission::Moderate->value,
                ],
            ]),
        );

        $service->bulkThreads(
            BulkThreadAction::Reject,
            [$thread->threadId],
            ModerationReasonCode::fromString('approval.rules'),
            ModerationRequestId::fromString('req-bulk-reject'),
            $this->time('2026-09-15 21:07:00.000000'),
        );

        self::assertSame(['bulk.thread.reject'], $repository->calls);
    }

    public function testMergeRequiresPermissionAcrossEveryParticipatingForum(): void
    {
        [$actor, $forumA, $forumB, $destination] = $this->fixture();
        $source = new ModerationThreadRecord(
            $this->id('d'),
            $forumB->id(),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            null,
        );
        $repository = new ModerationServiceRepository([$destination, $source]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA, $forumB]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => ['forum.view', ModerationPermission::MergeThread->value],
                $forumB->id()->value() => ['forum.view'],
            ]),
        );

        $this->expectException(PermissionDeniedException::class);
        try {
            $service->mergeThreads(
                $destination->threadId,
                [$source->threadId],
                ModerationReasonCode::fromString('duplicate'),
                ModerationRequestId::fromString('req-merge-denied'),
                $this->time('2026-09-15 21:10:00.000000'),
            );
        } finally {
            self::assertSame([], $repository->calls);
        }
    }

    public function testPostDeleteUsesExistingDeleteAnyPermissionNotStructuralThreadDelete(): void
    {
        [$actor, $forumA] = $this->fixture();
        $post = new ModerationPostRecord(
            $this->id('e'),
            $this->id('c'),
            $forumA->id(),
            2,
            PostModerationState::Visible,
            false,
        );
        $repository = new ModerationServiceRepository([], [$post]);
        $service = new ContentModerationService(
            new ModerationServiceNodeRepository([$forumA]),
            $repository,
            $this->gate($actor, [
                $forumA->id()->value() => ['forum.view', PostPermission::DeleteAny->value],
            ]),
        );

        $service->deletePost(
            $post->postId,
            ModerationReasonCode::fromString('cleanup'),
            ModerationRequestId::fromString('req-post-delete'),
            $this->time('2026-09-15 21:15:00.000000'),
        );

        self::assertSame(['post.deleted'], $repository->calls);
    }

    /** @return array{EntityId,ForumNode,ForumNode,ModerationThreadRecord} */
    private function fixture(): array
    {
        $actor = $this->id('1');
        $forumA = ForumNode::forum(
            $this->id('a'),
            null,
            'Forum A',
            ForumNodeSlug::fromString('forum-a'),
            new ForumSettings(),
        );
        $forumB = ForumNode::forum(
            $this->id('b'),
            null,
            'Forum B',
            ForumNodeSlug::fromString('forum-b'),
            new ForumSettings(),
        );
        $thread = new ModerationThreadRecord(
            $this->id('c'),
            $forumA->id(),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            null,
        );
        return [$actor, $forumA, $forumB, $thread];
    }

    /** @param array<string,list<string>> $allowedByNode */
    private function gate(EntityId $actor, array $allowedByNode): PermissionGate
    {
        $assignment = new UserAccessAssignment($actor, $this->id('f'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new ModerationServicePermissionRepository($actor, $allowedByNode)),
                new ModerationServiceAssignmentProvider($assignment),
            ),
            $actor,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class ModerationServiceNodeRepository implements ForumNodeRepository
{
    /** @param list<ForumNode> $nodes */
    public function __construct(private array $nodes)
    {
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id()->equals($nodeId)) {
                return $node;
            }
        }
        return null;
    }

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode
    {
        foreach ($this->nodes as $node) {
            if ($node->slug()->value() === $slug->value()) {
                return $node;
            }
        }
        return null;
    }

    public function all(): array
    {
        return $this->nodes;
    }

    public function save(ForumNode $node): void
    {
        throw new LogicException('Not used by moderation service test.');
    }

    public function delete(EntityId $nodeId): void
    {
        throw new LogicException('Not used by moderation service test.');
    }
}

final class ModerationServiceRepository implements ContentModerationRepository
{
    /** @var array<string,ModerationThreadRecord> */
    private array $threads = [];
    /** @var array<string,ModerationPostRecord> */
    private array $posts = [];
    /** @var list<string> */
    public array $calls = [];
    public ?ModerationAuditContext $lastContext = null;

    /** @param list<ModerationThreadRecord> $threads @param list<ModerationPostRecord> $posts */
    public function __construct(array $threads = [], array $posts = [])
    {
        foreach ($threads as $thread) {
            $this->threads[$thread->threadId->value()] = $thread;
        }
        foreach ($posts as $post) {
            $this->posts[$post->postId->value()] = $post;
        }
    }

    public function thread(EntityId $threadId): ?ModerationThreadRecord
    {
        return $this->threads[$threadId->value()] ?? null;
    }

    public function post(EntityId $postId): ?ModerationPostRecord
    {
        return $this->posts[$postId->value()] ?? null;
    }

    public function moveThread(EntityId $threadId, EntityId $targetForumNodeId, ModerationAuditContext $context): void
    {
        $this->calls[] = 'move';
        $this->lastContext = $context;
    }

    public function copyThread(EntityId $threadId, EntityId $targetForumNodeId, ModerationAuditContext $context): EntityId
    {
        $this->calls[] = 'copy';
        $this->lastContext = $context;
        return EntityId::fromString(str_repeat('e', 32));
    }

    public function mergeThreads(EntityId $destinationThreadId, array $sourceThreadIds, ModerationAuditContext $context): void
    {
        $this->calls[] = 'merge';
        $this->lastContext = $context;
    }

    public function splitThread(EntityId $sourceThreadId, array $postIds, EntityId $targetForumNodeId, ThreadTitle $newTitle, ModerationAuditContext $context): EntityId
    {
        $this->calls[] = 'split';
        $this->lastContext = $context;
        return EntityId::fromString(str_repeat('e', 32));
    }

    public function setThreadLocked(EntityId $threadId, bool $locked, ModerationAuditContext $context): void
    {
        $this->calls[] = $locked ? 'thread.locked' : 'thread.unlocked';
        $this->lastContext = $context;
    }

    public function setThreadSticky(EntityId $threadId, bool $sticky, ModerationAuditContext $context): void
    {
        $this->calls[] = $sticky ? 'thread.sticky' : 'thread.unsticky';
        $this->lastContext = $context;
    }

    public function approveThread(EntityId $threadId, ModerationAuditContext $context): void
    {
        $this->calls[] = 'thread.approved';
        $this->lastContext = $context;
    }

    public function setThreadDeleted(EntityId $threadId, bool $deleted, ModerationAuditContext $context): void
    {
        $this->calls[] = $deleted ? 'thread.deleted' : 'thread.restored';
        $this->lastContext = $context;
    }

    public function approvePost(EntityId $postId, ModerationAuditContext $context): void
    {
        $this->calls[] = 'post.approved';
        $this->lastContext = $context;
    }

    public function setPostDeleted(EntityId $postId, bool $deleted, ModerationAuditContext $context): void
    {
        $this->calls[] = $deleted ? 'post.deleted' : 'post.restored';
        $this->lastContext = $context;
    }

    public function bulkThreads(BulkThreadAction $action, array $threadIds, ModerationAuditContext $context): void
    {
        $this->calls[] = 'bulk.thread.' . $action->value;
        $this->lastContext = $context;
    }

    public function bulkPosts(BulkPostAction $action, array $postIds, ModerationAuditContext $context): void
    {
        $this->calls[] = 'bulk.post.' . $action->value;
        $this->lastContext = $context;
    }
}

final readonly class ModerationServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class ModerationServicePermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $allowedByNode */
    public function __construct(
        private readonly EntityId $actor,
        private readonly array $allowedByNode,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor) || $nodeId === null) {
            return [];
        }
        $allowed = $this->allowedByNode[$nodeId->value()] ?? [];
        if (!in_array($key->value(), $allowed, true)) {
            return [];
        }
        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
            $nodeId,
        )];
    }
}
