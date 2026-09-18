<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Post;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Pipeline\ContentPipeline;
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
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostCounters;
use Forwext\Core\Forum\Post\PostHistoryEntry;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostPage;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Post\PostService;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
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

final class PostServiceTest extends TestCase
{
    public function testFirstPostUsesThreadAuthorAndPendingState(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(true, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Pending, false);
        $posts = new PostServicePostRepository();
        $service = $this->service($actor, $forum, $thread, $posts, ['forum.view', 'forum.post.create']);

        $post = $service->createFirstPost(
            $thread->id(),
            PostBody::fromString('First body'),
            $this->time('2026-09-15 21:00:00.000000'),
        );

        self::assertTrue($post->isFirstPost());
        self::assertSame($actor->value(), $post->authorUserId()?->value());
        self::assertSame(PostModerationState::Pending, $post->moderationState());
    }

    public function testAbuseReviewForcesReplyIntoApprovalQueue(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Visible, false);
        $first = $this->post($thread->id(), $actor, 1, false);
        $posts = new PostServicePostRepository([$first]);
        $abuseRepository = new PostAbuseRepository(new AbuseRule(
            'post.user.review',
            'Post flood review',
            AbuseEventType::Post,
            AbuseSignal::User,
            1,
            60,
            AbuseAction::Review,
            true,
        ));
        $service = $this->service(
            $actor,
            $forum,
            $thread,
            $posts,
            ['forum.view', 'forum.post.create'],
            new AbuseEngine($abuseRepository),
        );

        $reply = $service->reply(
            $thread->id(),
            PostBody::fromString('Review this reply'),
            $this->time('2026-09-15 21:01:00.000000'),
        );

        self::assertSame(PostModerationState::Pending, $reply->moderationState());
        self::assertCount(1, $abuseRepository->events);
        self::assertSame('forum.post', $abuseRepository->events[0]->targetType);
        self::assertSame($reply->id()->value(), $abuseRepository->events[0]->targetId?->value());
    }

    public function testCommonContentPipelineAppliesReviewAndQueuesPostIndexChange(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Visible, false);
        $first = $this->post($thread->id(), $actor, 1, false);
        $posts = new PostServicePostRepository([$first]);
        $abuseRepository = new PostAbuseRepository(new AbuseRule(
            'post.user.pipeline-review',
            'Pipeline review',
            AbuseEventType::Post,
            AbuseSignal::User,
            1,
            60,
            AbuseAction::Review,
            true,
        ));
        $search = new PostPipelineSearchStore();
        $database = new PostPipelineDatabase();
        $pipeline = ForumContentPipelineFactory::create(
            $database,
            $search,
            new AbuseEngine($abuseRepository),
        );
        $service = $this->service(
            $actor,
            $forum,
            $thread,
            $posts,
            ['forum.view', 'forum.post.create'],
            null,
            $pipeline,
        );

        $reply = $service->reply(
            $thread->id(),
            PostBody::fromString('Pipeline review reply'),
            $this->time('2026-09-18 19:46:00.000000'),
        );

        self::assertSame(PostModerationState::Pending, $reply->moderationState());
        self::assertCount(1, $abuseRepository->events);
        self::assertSame('forum.post', $abuseRepository->events[0]->targetType);
        self::assertSame($reply->id()->value(), $abuseRepository->events[0]->targetId?->value());
        self::assertSame([['post', $reply->id()->value()]], $search->recorded);
        self::assertSame(1, $database->transactions);
    }

    public function testPostEditUsesPipelineAndCanReturnVisibleContentToModeration(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Visible, false);
        $first = $this->post($thread->id(), $actor, 1, false);
        $posts = new PostServicePostRepository([$first]);
        $abuseRepository = new PostAbuseRepository(new AbuseRule(
            'post.edit.pipeline-review',
            'Pipeline edit review',
            AbuseEventType::Post,
            AbuseSignal::User,
            1,
            60,
            AbuseAction::Review,
            true,
        ));
        $search = new PostPipelineSearchStore();
        $database = new PostPipelineDatabase();
        $pipeline = ForumContentPipelineFactory::create(
            $database,
            $search,
            new AbuseEngine($abuseRepository),
        );
        $service = $this->service(
            $actor,
            $forum,
            $thread,
            $posts,
            ['forum.view', PostPermission::EditOwn->value],
            null,
            $pipeline,
        );

        $edited = $service->edit(
            $first->id(),
            PostBody::fromString('Edited through pipeline'),
            $this->time('2026-09-18 19:47:00.000000'),
        );

        self::assertSame('Edited through pipeline', $edited->body()->source());
        self::assertSame(PostModerationState::Pending, $edited->moderationState());
        self::assertCount(1, $abuseRepository->events);
        self::assertSame('forum.post', $abuseRepository->events[0]->targetType);
        self::assertSame($edited->id()->value(), $abuseRepository->events[0]->targetId?->value());
        self::assertSame([['post', $edited->id()->value()]], $search->recorded);
        self::assertSame(1, $database->transactions);
    }

    public function testReplyIsBlockedWhenThreadIsLocked(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Visible, true);
        $first = $this->post($thread->id(), $actor, 1, false);
        $posts = new PostServicePostRepository([$first]);
        $service = $this->service($actor, $forum, $thread, $posts, ['forum.view', 'forum.post.create']);

        $this->expectException(\Forwext\Core\Forum\Post\PostOperationException::class);
        $service->reply(
            $thread->id(),
            PostBody::fromString('Reply'),
            $this->time('2026-09-15 21:01:00.000000'),
        );
    }

    public function testOwnEditPermissionCannotDeleteFirstPost(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $actor, ThreadModerationState::Visible, false);
        $first = $this->post($thread->id(), $actor, 1, false);
        $posts = new PostServicePostRepository([$first]);
        $service = $this->service($actor, $forum, $thread, $posts, [
            'forum.view',
            PostPermission::EditOwn->value,
            PostPermission::DeleteOwn->value,
        ]);

        $edited = $service->edit(
            $first->id(),
            PostBody::fromString('Edited own first post'),
            $this->time('2026-09-15 21:01:00.000000'),
        );
        self::assertSame('Edited own first post', $edited->body()->source());

        $this->expectException(PermissionDeniedException::class);
        $service->delete($first->id(), $this->time('2026-09-15 21:02:00.000000'));
    }

    public function testModeratorCanDeleteRestoreAndApproveAnotherPost(): void
    {
        $actor = $this->id('9');
        $author = $this->id('1');
        $forum = $this->forum(false, true);
        $thread = $this->thread($forum->id(), $author, ThreadModerationState::Visible, false);
        $first = $this->post($thread->id(), $author, 1, false);
        $reply = $this->post($thread->id(), $author, 2, true);
        $posts = new PostServicePostRepository([$first, $reply]);
        $service = $this->service($actor, $forum, $thread, $posts, [
            'forum.view',
            PostPermission::DeleteAny->value,
            PostPermission::Restore->value,
            PostPermission::Moderate->value,
        ]);

        $service->delete($reply->id(), $this->time('2026-09-15 21:01:00.000000'));
        self::assertTrue($reply->isDeleted());
        $service->restore($reply->id(), $this->time('2026-09-15 21:02:00.000000'));
        self::assertFalse($reply->isDeleted());
        $service->approve($reply->id(), $this->time('2026-09-15 21:03:00.000000'));
        self::assertSame(PostModerationState::Visible, $reply->moderationState());
    }

    /** @param list<string> $permissions */
    private function service(
        EntityId $actor,
        ForumNode $forum,
        Thread $thread,
        PostServicePostRepository $posts,
        array $permissions,
        ?AbuseEngine $abuse = null,
        ?ContentPipeline $pipeline = null,
    ): PostService {
        $assignment = new UserAccessAssignment($actor, $this->id('c'));
        $gate = new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new PostServicePermissionRepository($actor, $forum->id(), $permissions)),
                new PostServiceAssignmentProvider($assignment),
            ),
            $actor,
        );

        return new PostService(
            new PostServiceNodeRepository([$forum]),
            new PostServiceThreadRepository([$thread]),
            ThreadTypeRegistry::withCoreDefaults(),
            $posts,
            $gate,
            $abuse,
            $pipeline,
        );
    }

    private function forum(bool $postApproval, bool $allowReplies): ForumNode
    {
        return ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(
                allowReplies: $allowReplies,
                requirePostApproval: $postApproval,
            ),
        );
    }

    private function thread(
        EntityId $forumId,
        EntityId $author,
        ThreadModerationState $state,
        bool $locked,
    ): Thread {
        return Thread::hydrate(
            $this->id('b'),
            $forumId,
            $author,
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            $state,
            $locked,
            false,
            false,
            $this->time('2026-09-15 20:00:00.000000'),
            $this->time('2026-09-15 20:00:00.000000'),
            1,
        );
    }

    private function post(EntityId $threadId, EntityId $author, int $position, bool $pending): Post
    {
        return Post::hydrate(
            $this->id($position === 1 ? 'd' : 'e'),
            $threadId,
            $author,
            $position,
            PostBody::fromString('Body ' . $position),
            $pending ? PostModerationState::Pending : PostModerationState::Visible,
            false,
            null,
            $this->time('2026-09-15 20:00:00.000000'),
            $this->time('2026-09-15 20:00:00.000000'),
            1,
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

final class PostServiceNodeRepository implements ForumNodeRepository
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
        return null;
    }

    public function all(): array
    {
        return $this->nodes;
    }

    public function save(ForumNode $node): void
    {
        throw new \LogicException('Not used.');
    }

    public function delete(EntityId $nodeId): void
    {
        throw new \LogicException('Not used.');
    }
}

final class PostServiceThreadRepository implements ThreadRepository
{
    /** @var array<string, Thread> */
    private array $threads = [];

    /** @param list<Thread> $threads */
    public function __construct(array $threads)
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
        throw new \LogicException('Not used.');
    }
}

final class PostServicePostRepository implements PostRepository
{
    /** @var array<string, Post> */
    private array $posts = [];

    /** @param list<Post> $posts */
    public function __construct(array $posts = [])
    {
        foreach ($posts as $post) {
            $this->posts[$post->id()->value()] = $post;
        }
    }

    public function find(EntityId $postId): ?Post
    {
        return $this->posts[$postId->value()] ?? null;
    }

    public function firstPost(EntityId $threadId): ?Post
    {
        foreach ($this->posts as $post) {
            if ($post->threadId()->equals($threadId) && $post->isFirstPost()) {
                return $post;
            }
        }
        return null;
    }

    public function create(
        EntityId $threadId,
        EntityId $authorUserId,
        PostBody $body,
        bool $requiresApproval,
        bool $mustBeFirst,
        DateTimeImmutable $now,
    ): Post {
        $position = $mustBeFirst ? 1 : count(array_filter(
            $this->posts,
            static fn (Post $post): bool => $post->threadId()->equals($threadId),
        )) + 1;
        $post = Post::create(
            EntityId::fromString(str_repeat($position === 1 ? 'f' : '8', 32)),
            $threadId,
            $authorUserId,
            $position,
            $body,
            $requiresApproval,
            $now,
        );
        $post->markPersisted(1);
        $this->posts[$post->id()->value()] = $post;
        return $post;
    }

    public function save(Post $post): void
    {
        $post->markPersisted($post->version() + 1);
        $this->posts[$post->id()->value()] = $post;
    }

    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    public function pageByThread(
        EntityId $threadId,
        int $page = 1,
        int $perPage = 20,
        bool $includeDeleted = false,
        bool $includeNonVisible = false,
    ): PostPage {
        return new PostPage([], $page, $perPage, 0);
    }

    public function counters(EntityId $threadId): PostCounters
    {
        return new PostCounters(0, 0);
    }
}

final readonly class PostServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class PostServicePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(
        private readonly EntityId $actor,
        private readonly EntityId $nodeId,
        private readonly array $permissions,
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
            || !in_array($key->value(), $this->permissions, true)
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


final class PostAbuseRepository implements AbuseRepository
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


final class PostPipelineSearchStore implements SearchIndexChangeStore
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

final class PostPipelineDatabase implements TransactionalQueryExecutor
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
