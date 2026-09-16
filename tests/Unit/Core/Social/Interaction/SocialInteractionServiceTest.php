<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Social\Interaction;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
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
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserHistoryEntry;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostCounters;
use Forwext\Core\Forum\Post\PostHistoryEntry;
use Forwext\Core\Forum\Post\PostPage;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Social\Interaction\BookmarkEntry;
use Forwext\Core\Social\Interaction\ReactionSummary;
use Forwext\Core\Social\Interaction\ReactionType;
use Forwext\Core\Social\Interaction\SocialInteractionException;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;
use Forwext\Core\Social\Interaction\SocialInteractionService;
use PHPUnit\Framework\TestCase;

final class SocialInteractionServiceTest extends TestCase
{
    public function testOwnPostReactionIsRejectedBeforePersistence(): void
    {
        $actor = $this->id('1');
        $thread = $this->thread($this->id('3'), $this->id('4'), $actor);
        $post = $this->post($this->id('5'), $thread->id(), $actor);
        $interactions = new ServiceInteractionRepository();
        $service = $this->service($interactions, [$post], [$thread], [$this->user($actor, 'Actor')]);

        $this->expectException(SocialInteractionException::class);
        try {
            $service->react($actor, $post->id(), 'like');
        } finally {
            self::assertSame([], $interactions->reactionWrites);
        }
    }

    public function testBookmarkNoteIsPrivateBoundedAndControlSafe(): void
    {
        $actor = $this->id('1');
        $author = $this->id('2');
        $thread = $this->thread($this->id('3'), $this->id('4'), $author);
        $post = $this->post($this->id('5'), $thread->id(), $author);
        $interactions = new ServiceInteractionRepository();
        $service = $this->service(
            $interactions,
            [$post],
            [$thread],
            [$this->user($actor, 'Actor'), $this->user($author, 'Author')],
        );

        $service->bookmark($actor, $post->id(), ' private note ');
        self::assertSame('private note', $interactions->bookmarkWrites[$post->id()->value()] ?? null);

        $this->expectException(SocialInteractionException::class);
        $service->bookmark($actor, $post->id(), str_repeat('x', 1001));
    }

    public function testIgnoreFilterRemovesIgnoredAuthorsFromPostsAndThreads(): void
    {
        $actor = $this->id('1');
        $ignored = $this->id('2');
        $visible = $this->id('6');
        $threadA = $this->thread($this->id('3'), $this->id('4'), $ignored);
        $threadB = $this->thread($this->id('7'), $this->id('4'), $visible);
        $postA = $this->post($this->id('5'), $threadA->id(), $ignored);
        $postB = $this->post($this->id('8'), $threadB->id(), $visible);
        $interactions = new ServiceInteractionRepository();
        $interactions->ignored[$actor->value()] = [$ignored];
        $service = $this->service($interactions, [$postA, $postB], [$threadA, $threadB], []);

        self::assertSame([$postB->id()->value()], array_map(
            static fn (Post $post): string => $post->id()->value(),
            $service->filterIgnoredPosts($actor, [$postA, $postB]),
        ));
        self::assertSame([$threadB->id()->value()], array_map(
            static fn (Thread $thread): string => $thread->id()->value(),
            $service->filterIgnoredThreads($actor, [$threadA, $threadB]),
        ));
    }

    public function testFollowingAnIgnoredUserIsRejected(): void
    {
        $actor = $this->id('1');
        $target = $this->id('2');
        $interactions = new ServiceInteractionRepository();
        $interactions->ignored[$actor->value()] = [$target];
        $service = $this->service(
            $interactions,
            [],
            [],
            [$this->user($actor, 'Actor'), $this->user($target, 'Target')],
        );

        $this->expectException(SocialInteractionException::class);
        $service->follow($actor, $target);
    }

    /**
     * @param list<Post> $posts
     * @param list<Thread> $threads
     * @param list<User> $users
     */
    private function service(
        ServiceInteractionRepository $interactions,
        array $posts,
        array $threads,
        array $users,
    ): SocialInteractionService {
        return new SocialInteractionService(
            $interactions,
            new ServicePostRepository($posts),
            new ServiceThreadRepository($threads),
            new ServiceUserRepository($users),
            $this->authorizer(),
        );
    }

    private function authorizer(): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new ServiceAllowPermissionRepository()),
            new ServiceAssignmentProvider($this->id('a')),
        );
    }

    private function thread(EntityId $id, EntityId $forumId, EntityId $authorId): Thread
    {
        return Thread::create(
            $id,
            $forumId,
            $authorId,
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread title'),
            false,
            $this->now(),
        );
    }

    private function post(EntityId $id, EntityId $threadId, EntityId $authorId): Post
    {
        return Post::create($id, $threadId, $authorId, 1, PostBody::fromString('Post body'), false, $this->now());
    }

    private function user(EntityId $id, string $username): User
    {
        return User::create(
            $id,
            Username::fromString($username),
            EmailAddress::fromString(strtolower($username) . '@example.com'),
            UserStatus::Active,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('UTC'),
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-16 20:00:00', new DateTimeZone('UTC'));
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class ServiceInteractionRepository implements SocialInteractionRepository
{
    /** @var array<string,string> */
    public array $reactionWrites = [];
    /** @var array<string,string|null> */
    public array $bookmarkWrites = [];
    /** @var array<string,list<EntityId>> */
    public array $ignored = [];

    public function reactionType(string $key): ?ReactionType
    {
        return $key === 'like' ? new ReactionType('like', 'Like', 1) : null;
    }

    public function setReaction(EntityId $actorId, EntityId $postId, string $reactionKey): void
    {
        $this->reactionWrites[$postId->value()] = $reactionKey;
    }

    public function removeReaction(EntityId $actorId, EntityId $postId): void {}
    public function reactionSummary(EntityId $postId): ReactionSummary { return new ReactionSummary(0, 0, []); }

    public function saveBookmark(EntityId $actorId, EntityId $postId, ?string $note): void
    {
        $this->bookmarkWrites[$postId->value()] = $note;
    }

    public function removeBookmark(EntityId $actorId, EntityId $postId): void {}
    public function bookmarks(EntityId $actorId, int $limit = 50, int $offset = 0): array { return []; }
    public function follow(EntityId $actorId, EntityId $targetId): void {}
    public function unfollow(EntityId $actorId, EntityId $targetId): void {}

    public function ignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->ignored[$actorId->value()] = [$targetId];
    }

    public function unignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->ignored[$actorId->value()] = [];
    }

    public function isFollowing(EntityId $actorId, EntityId $targetId): bool { return false; }

    public function isIgnoring(EntityId $actorId, EntityId $targetId): bool
    {
        foreach ($this->ignored[$actorId->value()] ?? [] as $id) {
            if ($id->value() === $targetId->value()) return true;
        }
        return false;
    }

    public function ignoredUserIds(EntityId $actorId): array
    {
        return $this->ignored[$actorId->value()] ?? [];
    }
}

final class ServicePostRepository implements PostRepository
{
    /** @var array<string,Post> */
    private array $posts = [];
    /** @param list<Post> $posts */
    public function __construct(array $posts) { foreach ($posts as $post) $this->posts[$post->id()->value()] = $post; }
    public function find(EntityId $postId): ?Post { return $this->posts[$postId->value()] ?? null; }
    public function firstPost(EntityId $threadId): ?Post { return null; }
    public function create(EntityId $threadId, EntityId $authorUserId, PostBody $body, bool $requiresApproval, bool $mustBeFirst, DateTimeImmutable $now): Post { throw new \LogicException('Not used.'); }
    public function save(Post $post): void {}
    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array { return []; }
    public function pageByThread(EntityId $threadId, int $page = 1, int $perPage = 20, bool $includeDeleted = false, bool $includeNonVisible = false): PostPage { throw new \LogicException('Not used.'); }
    public function counters(EntityId $threadId): PostCounters { throw new \LogicException('Not used.'); }
}

final class ServiceThreadRepository implements ThreadRepository
{
    /** @var array<string,Thread> */
    private array $threads = [];
    /** @param list<Thread> $threads */
    public function __construct(array $threads) { foreach ($threads as $thread) $this->threads[$thread->id()->value()] = $thread; }
    public function find(EntityId $threadId): ?Thread { return $this->threads[$threadId->value()] ?? null; }
    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array { return []; }
    public function save(Thread $thread): void {}
}

final class ServiceUserRepository implements UserRepository
{
    /** @var array<string,User> */
    private array $users = [];
    /** @param list<User> $users */
    public function __construct(array $users) { foreach ($users as $user) $this->users[$user->id()->value()] = $user; }
    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $username): ?User { return null; }
    public function findByEmail(EmailAddress $email): ?User { return null; }
    public function save(User $user): void { $this->users[$user->id()->value()] = $user; }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final readonly class ServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $groupId) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return new UserAccessAssignment($userId, $this->groupId); }
}

final class ServiceAllowPermissionRepository implements PermissionRuleRepository
{
    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return [new PermissionRule(
            PermissionSubjectType::User,
            $assignment->userId(),
            PermissionEffect::Allow,
            $nodeId,
        )];
    }
}
