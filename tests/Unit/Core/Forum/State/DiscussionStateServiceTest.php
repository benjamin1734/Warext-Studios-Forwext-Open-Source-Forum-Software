<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\State;

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
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Forum\State\ContentDraft;
use Forwext\Core\Forum\State\DiscussionStateException;
use Forwext\Core\Forum\State\DiscussionStateRepository;
use Forwext\Core\Forum\State\DiscussionStateService;
use Forwext\Core\Forum\State\DraftTargetType;
use Forwext\Core\Forum\State\SubscriptionPreferences;
use Forwext\Core\Forum\State\WatchNotificationMode;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use PHPUnit\Framework\TestCase;

final class DiscussionStateServiceTest extends TestCase
{
    public function testNewThreadDraftRequiresThreadCreatePermission(): void
    {
        [$service, $forum] = $this->service(['forum.view']);

        $this->expectException(PermissionDeniedException::class);
        $service->saveThreadDraft(
            $forum->id(),
            'Draft title',
            'Draft body',
            0,
            $this->time('2026-09-15 22:30:00.000000'),
        );
    }

    public function testLockedThreadRejectsReplyDraft(): void
    {
        [$service, , $thread] = $this->service(['forum.view', 'forum.post.create'], true);

        $this->expectException(DiscussionStateException::class);
        $service->saveReplyDraft(
            $thread->id(),
            'Draft reply',
            0,
            $this->time('2026-09-15 22:31:00.000000'),
        );
    }

    public function testReadAndWatchStateAreBoundToAuthenticatedActor(): void
    {
        $state = new DiscussionStateSpyRepository();
        [$service, $forum, $thread, $actor] = $this->service(['forum.view'], false, $state);

        $service->markThreadRead($thread->id(), 3, $this->time('2026-09-15 22:32:00.000000'));
        $service->watchThread($thread->id(), WatchNotificationMode::InAppEmail, $this->time('2026-09-15 22:33:00.000000'));
        $service->watchForum($forum->id(), WatchNotificationMode::Email, $this->time('2026-09-15 22:34:00.000000'));

        self::assertSame($actor->value(), $state->actor?->value());
        self::assertSame(3, $state->readPosition);
        self::assertSame(WatchNotificationMode::InAppEmail, $state->threadMode);
        self::assertSame(WatchNotificationMode::Email, $state->forumMode);
    }

    /** @param list<string> $permissions
     *  @return array{0:DiscussionStateService,1:ForumNode,2:Thread,3:EntityId}
     */
    private function service(
        array $permissions,
        bool $locked = false,
        ?DiscussionStateSpyRepository $state = null,
    ): array {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(),
        );
        $thread = Thread::hydrate(
            $this->id('b'),
            $forum->id(),
            $actor,
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            ThreadModerationState::Visible,
            $locked,
            false,
            false,
            $this->time('2026-09-15 22:00:00.000000'),
            $this->time('2026-09-15 22:00:00.000000'),
            1,
        );
        $assignment = new UserAccessAssignment($actor, $this->id('c'));
        $gate = new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new DiscussionStatePermissionRules($actor, $forum->id(), $permissions)),
                new DiscussionStateAssignment($assignment),
            ),
            $actor,
        );
        return [
            new DiscussionStateService(
                new DiscussionStateNodes([$forum]),
                new DiscussionStateThreads([$thread]),
                $state ?? new DiscussionStateSpyRepository(),
                $gate,
            ),
            $forum,
            $thread,
            $actor,
        ];
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

final class DiscussionStateNodes implements ForumNodeRepository
{
    /** @param list<ForumNode> $nodes */
    public function __construct(private array $nodes) {}
    public function find(EntityId $nodeId): ?ForumNode { foreach ($this->nodes as $node) { if ($node->id()->equals($nodeId)) { return $node; } } return null; }
    public function findBySlug(ForumNodeSlug $slug): ?ForumNode { return null; }
    public function all(): array { return $this->nodes; }
    public function save(ForumNode $node): void { throw new \LogicException('Not used.'); }
    public function delete(EntityId $nodeId): void { throw new \LogicException('Not used.'); }
}

final class DiscussionStateThreads implements ThreadRepository
{
    /** @var array<string, Thread> */ private array $threads = [];
    /** @param list<Thread> $threads */ public function __construct(array $threads) { foreach ($threads as $thread) { $this->threads[$thread->id()->value()] = $thread; } }
    public function find(EntityId $threadId): ?Thread { return $this->threads[$threadId->value()] ?? null; }
    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array { return []; }
    public function save(Thread $thread): void { throw new \LogicException('Not used.'); }
}

final class DiscussionStateSpyRepository implements DiscussionStateRepository
{
    public ?EntityId $actor = null;
    public int $readPosition = 0;
    public ?WatchNotificationMode $threadMode = null;
    public ?WatchNotificationMode $forumMode = null;
    private SubscriptionPreferences $preferences;

    public function __construct() { $this->preferences = new SubscriptionPreferences(); }
    public function draft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): ?ContentDraft { $this->actor = $userId; return null; }
    public function saveDraft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId, ?string $titleSource, string $bodySource, int $expectedRevision, DateTimeImmutable $at): ContentDraft { $this->actor = $userId; return new ContentDraft($userId, $targetType, $targetId, $titleSource, $bodySource, $expectedRevision + 1, $at); }
    public function deleteDraft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): void { $this->actor = $userId; }
    public function markThreadRead(EntityId $userId, EntityId $threadId, int $postPosition, DateTimeImmutable $at): void { $this->actor = $userId; $this->readPosition = $postPosition; }
    public function markForumRead(EntityId $userId, EntityId $forumNodeId, DateTimeImmutable $at): void { $this->actor = $userId; }
    public function isThreadUnread(EntityId $userId, EntityId $threadId): bool { $this->actor = $userId; return true; }
    public function watchThread(EntityId $userId, EntityId $threadId, WatchNotificationMode $mode, DateTimeImmutable $at): void { $this->actor = $userId; $this->threadMode = $mode; }
    public function unwatchThread(EntityId $userId, EntityId $threadId): void { $this->actor = $userId; $this->threadMode = null; }
    public function threadWatch(EntityId $userId, EntityId $threadId): ?WatchNotificationMode { $this->actor = $userId; return $this->threadMode; }
    public function watchForum(EntityId $userId, EntityId $forumNodeId, WatchNotificationMode $mode, DateTimeImmutable $at): void { $this->actor = $userId; $this->forumMode = $mode; }
    public function unwatchForum(EntityId $userId, EntityId $forumNodeId): void { $this->actor = $userId; $this->forumMode = null; }
    public function forumWatch(EntityId $userId, EntityId $forumNodeId): ?WatchNotificationMode { $this->actor = $userId; return $this->forumMode; }
    public function subscriptionPreferences(EntityId $userId): SubscriptionPreferences { $this->actor = $userId; return $this->preferences; }
    public function saveSubscriptionPreferences(EntityId $userId, SubscriptionPreferences $preferences, DateTimeImmutable $at): void { $this->actor = $userId; $this->preferences = $preferences; }
}

final readonly class DiscussionStateAssignment implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return $this->assignment->userId()->equals($userId) ? $this->assignment : null; }
}

final class DiscussionStatePermissionRules implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private readonly EntityId $actor, private readonly EntityId $nodeId, private readonly array $permissions) {}
    public function definition(PermissionKey $key): ?PermissionDefinition { return new PermissionDefinition($key, PermissionValueType::Flag); }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor) || $nodeId === null || !$nodeId->equals($this->nodeId) || !in_array($key->value(), $this->permissions, true)) { return []; }
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow, $this->nodeId)];
    }
}
