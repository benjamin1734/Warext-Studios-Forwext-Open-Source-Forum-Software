<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Thread;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Pipeline\ForumContentPipelineFactory;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
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
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadCreationService;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadOperationException;
use Forwext\Core\Forum\Thread\ThreadPermission;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadStateService;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use Forwext\Core\Moderation\Abuse\AbuseAction;
use Forwext\Core\Moderation\Abuse\AbuseEngine;
use Forwext\Core\Moderation\Abuse\AbuseEvent;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use Forwext\Core\Moderation\Abuse\AbuseRepository;
use Forwext\Core\Moderation\Abuse\AbuseRule;
use Forwext\Core\Moderation\Abuse\AbuseSignal;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use PHPUnit\Framework\TestCase;

final class ThreadServiceTest extends TestCase
{
    public function testCreationUsesAuthenticatedActorForumSettingsAndNodePermissions(): void
    {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Moderated Forum',
            ForumNodeSlug::fromString('moderated-forum'),
            new ForumSettings(requireThreadApproval: true),
        );
        $threads = new ThreadServiceRepository();
        $gate = $this->gate($actor, $forum->id(), [
            'forum.view',
            'forum.thread.create',
        ]);

        $thread = (new ThreadCreationService(
            new ThreadServiceNodeRepository([$forum]),
            $threads,
            ThreadTypeRegistry::withCoreDefaults(),
            $gate,
        ))->create(
            $forum->id(),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Created by actor'),
            $this->time('2026-09-15 19:00:00.000000'),
        );

        self::assertSame($actor->value(), $thread->authorUserId()?->value());
        self::assertSame(ThreadModerationState::Pending, $thread->moderationState());
        self::assertCount(1, $threads->saved);
        self::assertSame(1, $thread->version());
    }

    public function testAbuseReviewForcesOtherwiseVisibleThreadIntoApprovalQueue(): void
    {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(requireThreadApproval: false),
        );
        $threads = new ThreadServiceRepository();
        $abuseRepository = new ThreadAbuseRepository(new AbuseRule(
            'thread.user.review',
            'Thread flood review',
            AbuseEventType::Thread,
            AbuseSignal::User,
            1,
            60,
            AbuseAction::Review,
            true,
        ));
        $service = new ThreadCreationService(
            new ThreadServiceNodeRepository([$forum]),
            $threads,
            ThreadTypeRegistry::withCoreDefaults(),
            $this->gate($actor, $forum->id(), ['forum.view', 'forum.thread.create']),
            new AbuseEngine($abuseRepository),
        );

        $thread = $service->create(
            $forum->id(),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Abuse reviewed thread'),
            $this->time('2026-09-15 19:00:00.000000'),
        );

        self::assertSame(ThreadModerationState::Pending, $thread->moderationState());
        self::assertCount(1, $abuseRepository->events);
        self::assertSame('forum.thread', $abuseRepository->events[0]->targetType);
        self::assertSame($thread->id()->value(), $abuseRepository->events[0]->targetId?->value());
    }

    public function testCommonContentPipelineAppliesReviewAndQueuesThreadIndexChange(): void
    {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(requireThreadApproval: false),
        );
        $threads = new ThreadServiceRepository();
        $abuseRepository = new ThreadAbuseRepository(new AbuseRule(
            'thread.user.pipeline-review',
            'Pipeline review',
            AbuseEventType::Thread,
            AbuseSignal::User,
            1,
            60,
            AbuseAction::Review,
            true,
        ));
        $search = new ThreadPipelineSearchStore();
        $database = new ThreadPipelineDatabase();
        $pipeline = ForumContentPipelineFactory::create(
            $database,
            $search,
            new AbuseEngine($abuseRepository),
        );
        $service = new ThreadCreationService(
            new ThreadServiceNodeRepository([$forum]),
            $threads,
            ThreadTypeRegistry::withCoreDefaults(),
            $this->gate($actor, $forum->id(), ['forum.view', 'forum.thread.create']),
            null,
            $pipeline,
        );

        $thread = $service->create(
            $forum->id(),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Pipeline thread'),
            $this->time('2026-09-18 19:45:00.000000'),
        );

        self::assertSame(ThreadModerationState::Pending, $thread->moderationState());
        self::assertCount(1, $abuseRepository->events);
        self::assertSame('forum.thread', $abuseRepository->events[0]->targetType);
        self::assertSame($thread->id()->value(), $abuseRepository->events[0]->targetId?->value());
        self::assertSame([['thread', $thread->id()->value()]], $search->recorded);
        self::assertSame(1, $database->transactions);
    }

    public function testCreationFailsWhenForumDisablesNewThreadsEvenWithPermission(): void
    {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Closed Forum',
            ForumNodeSlug::fromString('closed-forum'),
            new ForumSettings(allowNewThreads: false),
        );

        $service = new ThreadCreationService(
            new ThreadServiceNodeRepository([$forum]),
            new ThreadServiceRepository(),
            ThreadTypeRegistry::withCoreDefaults(),
            $this->gate($actor, $forum->id(), ['forum.view', 'forum.thread.create']),
        );

        $this->expectException(ThreadOperationException::class);
        $service->create(
            $forum->id(),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Blocked'),
            $this->time('2026-09-15 19:00:00.000000'),
        );
    }

    public function testCreationFailsWithoutThreadCreatePermission(): void
    {
        $actor = $this->id('1');
        $forum = ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(),
        );

        $service = new ThreadCreationService(
            new ThreadServiceNodeRepository([$forum]),
            new ThreadServiceRepository(),
            ThreadTypeRegistry::withCoreDefaults(),
            $this->gate($actor, $forum->id(), ['forum.view']),
        );

        $this->expectException(PermissionDeniedException::class);
        $service->create(
            $forum->id(),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Denied'),
            $this->time('2026-09-15 19:00:00.000000'),
        );
    }

    public function testGranularStatePermissionAllowsLockButNotStickyEscalation(): void
    {
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
            ThreadTitle::fromString('Stored'),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            $this->time('2026-09-15 18:00:00.000000'),
            $this->time('2026-09-15 18:00:00.000000'),
            2,
        );
        $threads = new ThreadServiceRepository([$thread]);
        $service = new ThreadStateService(
            new ThreadServiceNodeRepository([$forum]),
            $threads,
            $this->gate($actor, $forum->id(), ['forum.view', ThreadPermission::Lock->value]),
        );

        $locked = $service->lock($thread->id(), $this->time('2026-09-15 19:00:00.000000'));
        self::assertTrue($locked->isLocked());
        self::assertSame(3, $locked->version());

        try {
            $service->stick($thread->id(), $this->time('2026-09-15 19:01:00.000000'));
            self::fail('Lock permission must not imply sticky permission.');
        } catch (PermissionDeniedException) {
            self::assertFalse($thread->isSticky());
            self::assertCount(1, $threads->saved);
        }
    }

    public function testModerationPermissionCanApprovePendingThread(): void
    {
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
            $this->id('2'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Pending'),
            ThreadModerationState::Pending,
            false,
            false,
            false,
            $this->time('2026-09-15 18:00:00.000000'),
            $this->time('2026-09-15 18:00:00.000000'),
            7,
        );
        $threads = new ThreadServiceRepository([$thread]);
        $service = new ThreadStateService(
            new ThreadServiceNodeRepository([$forum]),
            $threads,
            $this->gate($actor, $forum->id(), ['forum.view', ThreadPermission::Moderate->value]),
        );

        $approved = $service->approve($thread->id(), $this->time('2026-09-15 19:00:00.000000'));

        self::assertSame(ThreadModerationState::Visible, $approved->moderationState());
        self::assertSame(8, $approved->version());
    }

    /** @param list<string> $allowed */
    private function gate(EntityId $actor, EntityId $nodeId, array $allowed): PermissionGate
    {
        $assignment = new UserAccessAssignment($actor, $this->id('c'));
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new ThreadServicePermissionRepository($actor, $nodeId, $allowed)),
            new ThreadServiceAssignmentProvider($assignment),
        );

        return new PermissionGate($authorizer, $actor);
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

final class ThreadServiceNodeRepository implements ForumNodeRepository
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
        throw new \LogicException('Not used by thread service test.');
    }

    public function delete(EntityId $nodeId): void
    {
        throw new \LogicException('Not used by thread service test.');
    }
}

final class ThreadServiceRepository implements ThreadRepository
{
    /** @var array<string, Thread> */
    private array $threads = [];
    /** @var list<Thread> */
    public array $saved = [];

    /** @param list<Thread> $threads */
    public function __construct(array $threads = [])
    {
        foreach ($threads as $thread) {
            $this->threads[$thread->id()->value()] = $thread;
        }
    }

    public function find(EntityId $threadId): ?Thread
    {
        return $this->threads[$threadId->value()] ?? null;
    }

    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function save(Thread $thread): void
    {
        $thread->markPersisted($thread->version() + 1);
        $this->threads[$thread->id()->value()] = $thread;
        $this->saved[] = $thread;
    }
}

final readonly class ThreadServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class ThreadServicePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $allowed */
    public function __construct(
        private readonly EntityId $actor,
        private readonly EntityId $nodeId,
        private readonly array $allowed,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor)
            || $nodeId === null
            || !$nodeId->equals($this->nodeId)
            || !in_array($key->value(), $this->allowed, true)
        ) {
            return [];
        }

        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
            $this->nodeId,
        )];
    }
}


final class ThreadAbuseRepository implements AbuseRepository
{
    /** @var list<AbuseEvent> */
    public array $events = [];

    public function __construct(private AbuseRule $rule)
    {
    }

    public function rules(AbuseEventType $eventType): array
    {
        return $eventType === $this->rule->eventType ? [$this->rule] : [];
    }

    public function allRules(): array
    {
        return [$this->rule];
    }

    public function rule(string $key): ?AbuseRule
    {
        return $this->rule->key === $key ? $this->rule : null;
    }

    public function saveRule(AbuseRule $rule, DateTimeImmutable $at): void
    {
        throw new \LogicException('Not used.');
    }

    public function consume(AbuseRule $rule, string $fingerprint, DateTimeImmutable $at): int
    {
        return $rule->limit + 1;
    }

    public function insertEvent(AbuseEvent $event): void
    {
        $this->events[] = $event;
    }

    public function event(EntityId $eventId): ?AbuseEvent
    {
        return null;
    }

    public function unresolved(int $limit = 100): array
    {
        return array_slice($this->events, 0, $limit);
    }

    public function unresolvedCount(): int
    {
        return count($this->events);
    }

    public function resolve(EntityId $eventId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void
    {
        throw new \LogicException('Not used.');
    }
}


final class ThreadPipelineSearchStore implements SearchIndexChangeStore
{
    /** @var list<array{0:string,1:string}> */
    public array $recorded = [];

    public function record(string $documentType, string $documentId): void
    {
        $this->recorded[] = [$documentType, $documentId];
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

final class ThreadPipelineDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;
    public int $transactions = 0;

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        $previous = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $previous;
        }
    }
}
