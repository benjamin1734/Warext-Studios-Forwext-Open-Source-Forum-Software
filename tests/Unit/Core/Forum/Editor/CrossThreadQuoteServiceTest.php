<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
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
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserHistoryEntry;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Forum\Editor\CrossThreadQuoteService;
use Forwext\Core\Forum\Editor\QuoteUnavailableException;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostCounters;
use Forwext\Core\Forum\Post\PostPage;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CrossThreadQuoteServiceTest extends TestCase
{
    public function testVisibleCrossThreadQuoteUsesOpaquePlainPayload(): void
    {
        $forumId = $this->id('a');
        $thread = $this->thread($forumId);
        $post = $this->post($thread->id(), false, 'Hello [/quote][b]unsafe[/b]');
        $service = new CrossThreadQuoteService(
            new QuotePostRepository([$post]),
            new QuoteThreadRepository([$thread]),
            new NullQuoteUserRepository(),
        );

        $quote = $service->quote($post->id(), $this->gate($this->id('2'), $forumId, ['forum.view']));

        self::assertStringContainsString('[plain64=', $quote->bbCode);
        self::assertStringNotContainsString('[/quote][b]unsafe', $quote->bbCode);
        self::assertSame($thread->id()->value(), $quote->threadId->value());
        self::assertSame(1, $quote->postPosition);
    }

    public function testPendingOtherUsersPostCannotBeQuotedWithoutModerationPermission(): void
    {
        $forumId = $this->id('a');
        $thread = $this->thread($forumId);
        $post = $this->post($thread->id(), true, 'Pending secret');
        $service = new CrossThreadQuoteService(
            new QuotePostRepository([$post]),
            new QuoteThreadRepository([$thread]),
            new NullQuoteUserRepository(),
        );

        $this->expectException(QuoteUnavailableException::class);
        $service->quote($post->id(), $this->gate($this->id('2'), $forumId, ['forum.view']));
    }

    public function testModeratorCanQuotePendingPostOnlyWithSourceForumVisibility(): void
    {
        $forumId = $this->id('a');
        $thread = $this->thread($forumId);
        $post = $this->post($thread->id(), true, 'Pending moderation');
        $service = new CrossThreadQuoteService(
            new QuotePostRepository([$post]),
            new QuoteThreadRepository([$thread]),
            new NullQuoteUserRepository(),
        );

        $quote = $service->quote(
            $post->id(),
            $this->gate($this->id('2'), $forumId, ['forum.view', 'forum.post.moderate']),
        );

        self::assertStringContainsString('[plain64=', $quote->bbCode);
    }

    private function thread(EntityId $forumId): Thread
    {
        return Thread::create(
            $this->id('b'),
            $forumId,
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Source Thread'),
            false,
            $this->time(),
        );
    }

    private function post(EntityId $threadId, bool $pending, string $body): Post
    {
        return Post::create(
            $this->id('c'),
            $threadId,
            $this->id('1'),
            1,
            PostBody::fromString($body),
            $pending,
            $this->time(),
        );
    }

    /** @param list<string> $allowed */
    private function gate(EntityId $actor, EntityId $forumId, array $allowed): PermissionGate
    {
        $assignment = new UserAccessAssignment($actor, $this->id('f'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new QuotePermissionRepository($actor, $forumId, $allowed)),
                new QuoteAssignmentProvider($assignment),
            ),
            $actor,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-16 06:00:00', new DateTimeZone('UTC'));
    }
}

final class QuotePostRepository implements PostRepository
{
    /** @var array<string,Post> */
    private array $posts = [];
    /** @param list<Post> $posts */
    public function __construct(array $posts) { foreach ($posts as $post) $this->posts[$post->id()->value()] = $post; }
    public function find(EntityId $postId): ?Post { return $this->posts[$postId->value()] ?? null; }
    public function firstPost(EntityId $threadId): ?Post { return null; }
    public function create(EntityId $threadId, EntityId $authorUserId, PostBody $body, bool $requiresApproval, bool $mustBeFirst, DateTimeImmutable $now): Post { throw new LogicException('Not used.'); }
    public function save(Post $post): void { throw new LogicException('Not used.'); }
    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array { return []; }
    public function pageByThread(EntityId $threadId, int $page = 1, int $perPage = 20, bool $includeDeleted = false, bool $includeNonVisible = false): PostPage { return new PostPage([], $page, $perPage, 0); }
    public function counters(EntityId $threadId): PostCounters { return new PostCounters(0, 0); }
}

final class QuoteThreadRepository implements ThreadRepository
{
    /** @var array<string,Thread> */
    private array $threads = [];
    /** @param list<Thread> $threads */
    public function __construct(array $threads) { foreach ($threads as $thread) $this->threads[$thread->id()->value()] = $thread; }
    public function find(EntityId $threadId): ?Thread { return $this->threads[$threadId->value()] ?? null; }
    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array { return []; }
    public function save(Thread $thread): void { throw new LogicException('Not used.'); }
}

final class NullQuoteUserRepository implements UserRepository
{
    public function find(EntityId $id): ?User { return null; }
    public function findByUsername(Username $username): ?User { return null; }
    public function findByEmail(EmailAddress $email): ?User { return null; }
    public function save(User $user): void { throw new LogicException('Not used.'); }
    /** @return list<UserHistoryEntry> */
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final readonly class QuoteAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment) {}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class QuotePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $allowed */
    public function __construct(
        private readonly EntityId $actor,
        private readonly EntityId $forumId,
        private readonly array $allowed,
    ) {}

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor)
            || $nodeId === null
            || !$nodeId->equals($this->forumId)
            || !in_array($key->value(), $this->allowed, true)
        ) {
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow, $nodeId)];
    }
}
