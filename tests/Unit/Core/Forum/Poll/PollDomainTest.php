<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Poll;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Poll\Poll;
use Forwext\Core\Forum\Poll\PollId;
use Forwext\Core\Forum\Poll\PollOption;
use Forwext\Core\Forum\Poll\PollOptionId;
use Forwext\Core\Forum\Poll\PollQuestion;
use Forwext\Core\Forum\Poll\PollResultVisibility;
use Forwext\Core\Forum\Poll\PollSelectionMode;
use Forwext\Core\Forum\Poll\PollVoterVisibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PollDomainTest extends TestCase
{
    public function testSingleChoiceRequiresExactlyOneSelection(): void
    {
        $poll = $this->poll(PollSelectionMode::Single, 1, PollResultVisibility::Always);
        $selected = $poll->validateSelection([$poll->options()[0]->id()]);
        self::assertCount(1, $selected);

        $this->expectException(InvalidArgumentException::class);
        $poll->validateSelection([$poll->options()[0]->id(), $poll->options()[1]->id()]);
    }

    public function testMultipleChoiceDeduplicatesButEnforcesMaximum(): void
    {
        $poll = $this->poll(PollSelectionMode::Multiple, 2, PollResultVisibility::Always);
        $same = $poll->options()[0]->id();
        self::assertCount(1, $poll->validateSelection([$same, $same]));

        $this->expectException(InvalidArgumentException::class);
        $poll->validateSelection(array_map(
            static fn (PollOption $option): EntityId => $option->id(),
            $poll->options(),
        ));
    }

    public function testForeignOptionCannotBeInjected(): void
    {
        $poll = $this->poll(PollSelectionMode::Single, 1, PollResultVisibility::Always);
        $this->expectException(InvalidArgumentException::class);
        $poll->validateSelection([EntityId::fromString(str_repeat('f', 32))]);
    }

    public function testTimeAndParticipantLimitClosePoll(): void
    {
        $poll = $this->poll(
            PollSelectionMode::Single,
            1,
            PollResultVisibility::AfterClose,
            $this->time('2026-09-15 22:30:00.000000'),
            10,
        );
        self::assertFalse($poll->isClosed($this->time('2026-09-15 22:29:59.000000'), 9));
        self::assertTrue($poll->isClosed($this->time('2026-09-15 22:30:00.000000'), 9));
        self::assertTrue($poll->isClosed($this->time('2026-09-15 22:20:00.000000'), 10));
    }

    public function testAfterVoteResultsRequireVoteUntilPollClosesPolicyIsDifferent(): void
    {
        $poll = $this->poll(PollSelectionMode::Single, 1, PollResultVisibility::AfterVote);
        self::assertFalse($poll->canViewResults(false, $this->time('2026-09-15 22:20:00.000000'), 0));
        self::assertTrue($poll->canViewResults(true, $this->time('2026-09-15 22:20:00.000000'), 1));
    }

    public function testManualCloseIsIdempotent(): void
    {
        $poll = $this->poll(PollSelectionMode::Single, 1, PollResultVisibility::AfterClose);
        self::assertTrue($poll->close($this->time('2026-09-15 22:10:00.000000')));
        self::assertFalse($poll->close($this->time('2026-09-15 22:11:00.000000')));
        self::assertTrue($poll->canViewResults(false, $this->time('2026-09-15 22:12:00.000000'), 0));
    }

    private function poll(
        PollSelectionMode $mode,
        int $maxSelections,
        PollResultVisibility $resultVisibility,
        ?DateTimeImmutable $closesAt = null,
        ?int $maxVoters = null,
    ): Poll {
        return Poll::create(
            PollId::fromStored(str_repeat('a', 32)),
            EntityId::fromString(str_repeat('b', 32)),
            EntityId::fromString(str_repeat('1', 32)),
            PollQuestion::fromString('Which option?'),
            $mode,
            $maxSelections,
            true,
            PollVoterVisibility::Secret,
            $resultVisibility,
            $closesAt,
            $maxVoters,
            [
                new PollOption(PollOptionId::fromStored(str_repeat('c', 32)), 'One', 0),
                new PollOption(PollOptionId::fromStored(str_repeat('d', 32)), 'Two', 1),
                new PollOption(PollOptionId::fromStored(str_repeat('e', 32)), 'Three', 2),
            ],
            $this->time('2026-09-15 22:00:00.000000'),
        );
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}
