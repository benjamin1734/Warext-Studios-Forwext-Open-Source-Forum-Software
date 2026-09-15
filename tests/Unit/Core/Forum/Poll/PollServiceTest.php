<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Poll;

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
use Forwext\Core\Forum\Poll\Poll;
use Forwext\Core\Forum\Poll\PollId;
use Forwext\Core\Forum\Poll\PollOption;
use Forwext\Core\Forum\Poll\PollOptionId;
use Forwext\Core\Forum\Poll\PollOptionResult;
use Forwext\Core\Forum\Poll\PollOperationException;
use Forwext\Core\Forum\Poll\PollPermission;
use Forwext\Core\Forum\Poll\PollQuestion;
use Forwext\Core\Forum\Poll\PollRepository;
use Forwext\Core\Forum\Poll\PollResultVisibility;
use Forwext\Core\Forum\Poll\PollResults;
use Forwext\Core\Forum\Poll\PollSelectionMode;
use Forwext\Core\Forum\Poll\PollService;
use Forwext\Core\Forum\Poll\PollVoterVisibility;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use PHPUnit\Framework\TestCase;

final class PollServiceTest extends TestCase
{
    public function testOwnerCanCreatePollOnOwnThread(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $polls = new PollServicePollRepository();
        $service = $this->service($actor, $forum, $thread, $polls, ['forum.view', 'forum.poll.create']);

        $poll = $service->create(
            $thread->id(),
            PollQuestion::fromString('Choose'),
            ['One', 'Two'],
            PollSelectionMode::Single,
            1,
            true,
            PollVoterVisibility::Open,
            PollResultVisibility::Always,
            null,
            null,
            $this->time('2026-09-15 22:00:00.000000'),
        );

        self::assertSame($thread->id()->value(), $poll->threadId()->value());
        self::assertSame($actor->value(), $poll->creatorUserId()?->value());
        self::assertCount(2, $poll->options());
        self::assertSame($poll->id()->value(), $polls->findByThread($thread->id())?->id()->value());
    }

    public function testNonOwnerNeedsEditAnyEvenWithPollCreatePermission(): void
    {
        $actor = $this->id('9');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $this->id('1'));
        $service = $this->service(
            $actor,
            $forum,
            $thread,
            new PollServicePollRepository(),
            ['forum.view', 'forum.poll.create'],
        );

        $this->expectException(PermissionDeniedException::class);
        $service->create(
            $thread->id(),
            PollQuestion::fromString('Choose'),
            ['One', 'Two'],
            PollSelectionMode::Single,
            1,
            false,
            PollVoterVisibility::Open,
            PollResultVisibility::Always,
            null,
            null,
            $this->time('2026-09-15 22:00:00.000000'),
        );
    }

    public function testAfterVoteResultsStayHiddenUntilActorVotes(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $poll = $this->poll($thread->id(), PollVoterVisibility::Open, PollResultVisibility::AfterVote);
        $polls = new PollServicePollRepository([$poll]);
        $service = $this->service($actor, $forum, $thread, $polls, [
            'forum.view',
            PollPermission::Vote->value,
            PollPermission::ViewResults->value,
            PollPermission::ViewVoters->value,
        ]);

        try {
            $service->results($poll->id(), $this->time('2026-09-15 22:10:00.000000'));
            self::fail('Results should be hidden before voting.');
        } catch (PollOperationException) {
            self::assertFalse($polls->hasVoted($poll->id(), $actor));
        }

        $service->vote($poll->id(), [$poll->options()[0]->id()], $this->time('2026-09-15 22:11:00.000000'));
        $results = $service->results($poll->id(), $this->time('2026-09-15 22:12:00.000000'));
        self::assertSame(1, $results->totalVoters);
        self::assertTrue($polls->lastIncludeVoters);
    }

    public function testSecretPollSuppressesVoterIdentityEvenWithPermission(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $poll = $this->poll($thread->id(), PollVoterVisibility::Secret, PollResultVisibility::Always);
        $polls = new PollServicePollRepository([$poll]);
        $service = $this->service($actor, $forum, $thread, $polls, [
            'forum.view',
            PollPermission::ViewResults->value,
            PollPermission::ViewVoters->value,
        ]);

        $service->results($poll->id(), $this->time('2026-09-15 22:10:00.000000'));
        self::assertFalse($polls->lastIncludeVoters);
    }

    /** @param list<string> $permissions */
    private function service(
        EntityId $actor,
        ForumNode $forum,
        Thread $thread,
        PollServicePollRepository $polls,
        array $permissions,
    ): PollService {
        $assignment = new UserAccessAssignment($actor, $this->id('c'));
        $gate = new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new PollServicePermissionRepository($actor, $forum->id(), $permissions)),
                new PollServiceAssignmentProvider($assignment),
            ),
            $actor,
        );

        return new PollService(
            new PollServiceNodeRepository([$forum]),
            new PollServiceThreadRepository([$thread]),
            $polls,
            $gate,
        );
    }

    private function forum(): ForumNode
    {
        return ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(),
        );
    }

    private function thread(EntityId $forumId, EntityId $author): Thread
    {
        return Thread::hydrate(
            $this->id('b'),
            $forumId,
            $author,
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            $this->time('2026-09-15 21:00:00.000000'),
            $this->time('2026-09-15 21:00:00.000000'),
            1,
        );
    }

    private function poll(EntityId $threadId, PollVoterVisibility $voters, PollResultVisibility $results): Poll
    {
        return Poll::create(
            $this->id('d'),
            $threadId,
            $this->id('1'),
            PollQuestion::fromString('Choose'),
            PollSelectionMode::Single,
            1,
            true,
            $voters,
            $results,
            null,
            null,
            [
                new PollOption($this->id('e'), 'One', 0),
                new PollOption($this->id('f'), 'Two', 1),
            ],
            $this->time('2026-09-15 22:00:00.000000'),
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

final class PollServiceNodeRepository implements ForumNodeRepository
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

final class PollServiceThreadRepository implements ThreadRepository
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

final class PollServicePollRepository implements PollRepository
{
    /** @var array<string, Poll> */
    private array $polls = [];
    /** @var array<string, array<string, list<EntityId>>> */
    private array $votes = [];
    public bool $lastIncludeVoters = false;

    /** @param list<Poll> $polls */
    public function __construct(array $polls = [])
    {
        foreach ($polls as $poll) {
            $this->polls[$poll->id()->value()] = $poll;
        }
    }

    public function find(EntityId $pollId): ?Poll
    {
        return $this->polls[$pollId->value()] ?? null;
    }

    public function findByThread(EntityId $threadId): ?Poll
    {
        foreach ($this->polls as $poll) {
            if ($poll->threadId()->equals($threadId)) {
                return $poll;
            }
        }
        return null;
    }

    public function create(Poll $poll): void
    {
        $this->polls[$poll->id()->value()] = $poll;
    }

    public function castVote(EntityId $pollId, EntityId $userId, array $optionIds, DateTimeImmutable $at): void
    {
        $poll = $this->find($pollId) ?? throw new PollOperationException('Poll is unavailable.');
        $selected = $poll->validateSelection($optionIds);
        $existing = $this->votes[$pollId->value()][$userId->value()] ?? null;
        if ($existing !== null && !$poll->canChangeVote()) {
            throw new PollOperationException('Vote changes are disabled.');
        }
        if ($poll->isClosed($at, $this->voterCount($pollId))) {
            throw new PollOperationException('Poll is closed.');
        }
        $this->votes[$pollId->value()][$userId->value()] = $selected;
    }

    public function hasVoted(EntityId $pollId, EntityId $userId): bool
    {
        return isset($this->votes[$pollId->value()][$userId->value()]);
    }

    public function voterCount(EntityId $pollId): int
    {
        return count($this->votes[$pollId->value()] ?? []);
    }

    public function results(Poll $poll, DateTimeImmutable $at, bool $includeVoters): PollResults
    {
        $this->lastIncludeVoters = $includeVoters;
        $counts = [];
        $voters = [];
        foreach ($this->votes[$poll->id()->value()] ?? [] as $userId => $optionIds) {
            foreach ($optionIds as $optionId) {
                $counts[$optionId->value()] = ($counts[$optionId->value()] ?? 0) + 1;
                if ($includeVoters) {
                    $voters[$optionId->value()][] = EntityId::fromString($userId);
                }
            }
        }
        $optionResults = [];
        foreach ($poll->options() as $option) {
            $optionResults[] = new PollOptionResult(
                $option,
                $counts[$option->id()->value()] ?? 0,
                $voters[$option->id()->value()] ?? [],
            );
        }
        $total = $this->voterCount($poll->id());
        return new PollResults($poll->id(), $total, $poll->isClosed($at, $total), $optionResults);
    }

    public function close(EntityId $pollId, DateTimeImmutable $at): void
    {
        ($this->find($pollId) ?? throw new PollOperationException('Poll is unavailable.'))->close($at);
    }
}

final readonly class PollServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class PollServicePermissionRepository implements PermissionRuleRepository
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
