<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;

final class Poll
{
    /** @var list<PollOption> */
    private array $options;

    /** @param list<PollOption> $options */
    private function __construct(
        private readonly EntityId $id,
        private readonly EntityId $threadId,
        private readonly ?EntityId $creatorUserId,
        private PollQuestion $question,
        private readonly PollSelectionMode $selectionMode,
        private readonly int $maxSelections,
        private readonly bool $changeVote,
        private readonly PollVoterVisibility $voterVisibility,
        private readonly PollResultVisibility $resultVisibility,
        private readonly ?DateTimeImmutable $closesAt,
        private readonly ?int $maxVoters,
        array $options,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $closedAt = null,
    ) {
        PollId::assert($this->id);
        ThreadId::assert($this->threadId);
        if ($this->creatorUserId !== null) {
            UserId::assert($this->creatorUserId);
        }
        if (count($options) < 2 || count($options) > 20) {
            throw new InvalidArgumentException('Polls require 2-20 options.');
        }
        $ids = [];
        $orders = [];
        foreach ($options as $option) {
            $id = $option->id()->value();
            if (isset($ids[$id]) || isset($orders[$option->sortOrder()])) {
                throw new InvalidArgumentException('Poll option ids and sort order must be unique.');
            }
            $ids[$id] = true;
            $orders[$option->sortOrder()] = true;
        }
        usort($options, static fn (PollOption $a, PollOption $b): int => [$a->sortOrder(), $a->id()->value()] <=> [$b->sortOrder(), $b->id()->value()]);
        $this->options = array_values($options);

        if ($this->selectionMode === PollSelectionMode::Single && $this->maxSelections !== 1) {
            throw new InvalidArgumentException('Single-choice polls must allow exactly one selection.');
        }
        if ($this->selectionMode === PollSelectionMode::Multiple
            && ($this->maxSelections < 2 || $this->maxSelections > count($this->options))
        ) {
            throw new InvalidArgumentException('Multiple-choice selection limit must be between 2 and the option count.');
        }
        if ($this->maxVoters !== null && ($this->maxVoters < 1 || $this->maxVoters > 1000000)) {
            throw new InvalidArgumentException('Poll voter limit must be between 1 and 1000000.');
        }
        if ($this->closesAt !== null && self::utc($this->closesAt) <= self::utc($this->createdAt)) {
            throw new InvalidArgumentException('Poll closing time must be after creation time.');
        }
        if ($this->closedAt !== null && self::utc($this->closedAt) < self::utc($this->createdAt)) {
            throw new InvalidArgumentException('Poll manual close time cannot be before creation.');
        }
    }

    /** @param list<PollOption> $options */
    public static function create(
        EntityId $id,
        EntityId $threadId,
        EntityId $creatorUserId,
        PollQuestion $question,
        PollSelectionMode $selectionMode,
        int $maxSelections,
        bool $changeVote,
        PollVoterVisibility $voterVisibility,
        PollResultVisibility $resultVisibility,
        ?DateTimeImmutable $closesAt,
        ?int $maxVoters,
        array $options,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $threadId,
            $creatorUserId,
            $question,
            $selectionMode,
            $maxSelections,
            $changeVote,
            $voterVisibility,
            $resultVisibility,
            $closesAt === null ? null : self::utc($closesAt),
            $maxVoters,
            $options,
            self::utc($createdAt),
        );
    }

    /** @param list<PollOption> $options */
    public static function hydrate(
        EntityId $id,
        EntityId $threadId,
        ?EntityId $creatorUserId,
        PollQuestion $question,
        PollSelectionMode $selectionMode,
        int $maxSelections,
        bool $changeVote,
        PollVoterVisibility $voterVisibility,
        PollResultVisibility $resultVisibility,
        ?DateTimeImmutable $closesAt,
        ?int $maxVoters,
        array $options,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $closedAt,
    ): self {
        return new self(
            $id,
            $threadId,
            $creatorUserId,
            $question,
            $selectionMode,
            $maxSelections,
            $changeVote,
            $voterVisibility,
            $resultVisibility,
            $closesAt === null ? null : self::utc($closesAt),
            $maxVoters,
            $options,
            self::utc($createdAt),
            $closedAt === null ? null : self::utc($closedAt),
        );
    }

    public function id(): EntityId { return $this->id; }
    public function threadId(): EntityId { return $this->threadId; }
    public function creatorUserId(): ?EntityId { return $this->creatorUserId; }
    public function question(): PollQuestion { return $this->question; }
    public function selectionMode(): PollSelectionMode { return $this->selectionMode; }
    public function maxSelections(): int { return $this->maxSelections; }
    public function canChangeVote(): bool { return $this->changeVote; }
    public function voterVisibility(): PollVoterVisibility { return $this->voterVisibility; }
    public function resultVisibility(): PollResultVisibility { return $this->resultVisibility; }
    public function closesAt(): ?DateTimeImmutable { return $this->closesAt; }
    public function maxVoters(): ?int { return $this->maxVoters; }
    /** @return list<PollOption> */
    public function options(): array { return $this->options; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function closedAt(): ?DateTimeImmutable { return $this->closedAt; }

    public function close(DateTimeImmutable $at): bool
    {
        if ($this->closedAt !== null) {
            return false;
        }
        $at = self::utc($at);
        if ($at < $this->createdAt) {
            throw new InvalidArgumentException('Poll close time cannot be before creation.');
        }
        $this->closedAt = $at;
        return true;
    }

    public function isClosed(DateTimeImmutable $at, int $voterCount): bool
    {
        if ($voterCount < 0) {
            throw new InvalidArgumentException('Poll voter count cannot be negative.');
        }
        $at = self::utc($at);
        return $this->closedAt !== null
            || ($this->closesAt !== null && $at >= $this->closesAt)
            || ($this->maxVoters !== null && $voterCount >= $this->maxVoters);
    }

    public function canViewResults(bool $hasVoted, DateTimeImmutable $at, int $voterCount): bool
    {
        return match ($this->resultVisibility) {
            PollResultVisibility::Always => true,
            PollResultVisibility::AfterVote => $hasVoted,
            PollResultVisibility::AfterClose => $this->isClosed($at, $voterCount),
        };
    }

    /** @param list<EntityId> $optionIds @return list<EntityId> */
    public function validateSelection(array $optionIds): array
    {
        $allowed = [];
        foreach ($this->options as $option) {
            $allowed[$option->id()->value()] = true;
        }
        $selected = [];
        foreach ($optionIds as $optionId) {
            PollOptionId::assert($optionId);
            if (!isset($allowed[$optionId->value()])) {
                throw new InvalidArgumentException('Poll selection contains an option from another poll.');
            }
            $selected[$optionId->value()] = $optionId;
        }
        $count = count($selected);
        if ($this->selectionMode === PollSelectionMode::Single && $count !== 1) {
            throw new InvalidArgumentException('Single-choice polls require exactly one selected option.');
        }
        if ($this->selectionMode === PollSelectionMode::Multiple && ($count < 1 || $count > $this->maxSelections)) {
            throw new InvalidArgumentException('Multiple-choice selection count is outside the poll limit.');
        }
        return array_values($selected);
    }

    private static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
