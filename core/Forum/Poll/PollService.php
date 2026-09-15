<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Metadata\ThreadMetadataPermission;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use InvalidArgumentException;

final readonly class PollService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private PollRepository $polls,
        private PermissionGate $gate,
    ) {
    }

    /** @param list<string> $optionTexts */
    public function create(
        EntityId $threadId,
        PollQuestion $question,
        array $optionTexts,
        PollSelectionMode $selectionMode,
        int $maxSelections,
        bool $changeVote,
        PollVoterVisibility $voterVisibility,
        PollResultVisibility $resultVisibility,
        ?DateTimeImmutable $closesAt,
        ?int $maxVoters,
        DateTimeImmutable $now,
    ): Poll {
        [$thread, $hierarchy] = $this->threadContext($threadId);
        $forumId = $thread->forumNodeId();
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumId);
        $this->gate->require(PollPermission::Create->key(), $forumId);

        $author = $thread->authorUserId();
        if ($author === null || !$author->equals($this->gate->actorId())) {
            $this->gate->require(ThreadMetadataPermission::EditAny->key(), $forumId);
        }
        if ($thread->moderationState() === ThreadModerationState::Rejected) {
            throw new PollOperationException('Rejected threads cannot receive polls.');
        }
        if ($this->polls->findByThread($threadId) !== null) {
            throw new PollOperationException('Thread already has a poll.');
        }
        if (count($optionTexts) < 2 || count($optionTexts) > 20) {
            throw new InvalidArgumentException('Polls require 2-20 options.');
        }

        $options = [];
        foreach (array_values($optionTexts) as $index => $text) {
            if (!is_string($text)) {
                throw new InvalidArgumentException('Poll option text must be a string.');
            }
            $options[] = new PollOption(PollOptionId::generate(), $text, $index);
        }

        $poll = Poll::create(
            PollId::generate(),
            $threadId,
            $this->gate->actorId(),
            $question,
            $selectionMode,
            $maxSelections,
            $changeVote,
            $voterVisibility,
            $resultVisibility,
            $closesAt,
            $maxVoters,
            $options,
            $now,
        );
        $this->polls->create($poll);
        return $poll;
    }

    /** @param list<EntityId> $optionIds */
    public function vote(EntityId $pollId, array $optionIds, DateTimeImmutable $at): void
    {
        [$poll, $thread, $hierarchy] = $this->pollContext($pollId);
        $forumId = $thread->forumNodeId();
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumId);
        $this->gate->require(PollPermission::Vote->key(), $forumId);
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            throw new PollOperationException('Voting is unavailable while the thread is not visible.');
        }
        $this->polls->castVote($poll->id(), $this->gate->actorId(), $optionIds, $at);
    }

    public function results(EntityId $pollId, DateTimeImmutable $at): PollResults
    {
        [$poll, $thread, $hierarchy] = $this->pollContext($pollId);
        $forumId = $thread->forumNodeId();
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumId);
        $this->gate->require(PollPermission::ViewResults->key(), $forumId);
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            throw new PollOperationException('Poll results are unavailable while the thread is not visible.');
        }

        $actor = $this->gate->actorId();
        $voterCount = $this->polls->voterCount($pollId);
        $hasVoted = $this->polls->hasVoted($pollId, $actor);
        if (!$poll->canViewResults($hasVoted, $at, $voterCount)) {
            throw new PollOperationException('Poll results are not available yet.');
        }

        $includeVoters = $poll->voterVisibility() === PollVoterVisibility::Open
            && $this->gate->allows(PollPermission::ViewVoters->key(), $forumId);
        return $this->polls->results($poll, $at, $includeVoters);
    }

    public function close(EntityId $pollId, DateTimeImmutable $at): void
    {
        [$poll, $thread, $hierarchy] = $this->pollContext($pollId);
        $forumId = $thread->forumNodeId();
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumId);
        $this->gate->require(PollPermission::Manage->key(), $forumId);
        $this->polls->close($poll->id(), $at);
    }

    /** @return array{0:Poll,1:Thread,2:ForumNodeHierarchy} */
    private function pollContext(EntityId $pollId): array
    {
        $poll = $this->polls->find($pollId)
            ?? throw new PollOperationException('Poll is not available.');
        [$thread, $hierarchy] = $this->threadContext($poll->threadId());
        return [$poll, $thread, $hierarchy];
    }

    /** @return array{0:Thread,1:ForumNodeHierarchy} */
    private function threadContext(EntityId $threadId): array
    {
        $thread = $this->threads->find($threadId)
            ?? throw new PollOperationException('Thread is not available.');
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new PollOperationException('Poll forum is not available.');
        }
        return [$thread, $hierarchy];
    }
}
