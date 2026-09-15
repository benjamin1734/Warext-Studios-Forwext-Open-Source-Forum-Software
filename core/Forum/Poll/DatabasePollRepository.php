<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Thread\ThreadId;
use RuntimeException;

final readonly class DatabasePollRepository implements PollRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $pollId): ?Poll
    {
        PollId::assert($pollId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectPollSql() . ' WHERE `poll_id` = :poll_id LIMIT 1',
            ['poll_id' => $pollId->value()],
        ));

        return $row === null ? null : $this->hydrate($row, $this->loadOptions($pollId));
    }

    public function findByThread(EntityId $threadId): ?Poll
    {
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectPollSql() . ' WHERE `thread_id` = :thread_id LIMIT 1',
            ['thread_id' => $threadId->value()],
        ));
        if ($row === null) {
            return null;
        }
        $pollId = PollId::fromStored((string) $row['poll_id']);
        return $this->hydrate($row, $this->loadOptions($pollId));
    }

    public function create(Poll $poll): void
    {
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($poll): void {
            $affected = $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_polls` '
                . '(`poll_id`, `thread_id`, `creator_user_id`, `question`, `selection_mode`, `max_selections`, '
                . '`change_vote`, `voter_visibility`, `result_visibility`, `closes_at_utc`, `max_voters`, '
                . '`closed_at_utc`, `created_at_utc`) VALUES '
                . '(:poll_id, :thread_id, :creator_user_id, :question, :selection_mode, :max_selections, '
                . ':change_vote, :voter_visibility, :result_visibility, :closes_at, :max_voters, NULL, :created_at)',
                [
                    'poll_id' => $poll->id()->value(),
                    'thread_id' => $poll->threadId()->value(),
                    'creator_user_id' => $poll->creatorUserId()?->value(),
                    'question' => $poll->question()->value(),
                    'selection_mode' => $poll->selectionMode()->value,
                    'max_selections' => $poll->maxSelections(),
                    'change_vote' => $poll->canChangeVote(),
                    'voter_visibility' => $poll->voterVisibility()->value,
                    'result_visibility' => $poll->resultVisibility()->value,
                    'closes_at' => $poll->closesAt() === null ? null : self::format($poll->closesAt()),
                    'max_voters' => $poll->maxVoters(),
                    'created_at' => self::format($poll->createdAt()),
                ],
            ));
            if ($affected !== 1) {
                throw new PollOperationException('Poll could not be created.');
            }

            foreach ($poll->options() as $option) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_poll_options` (`option_id`, `poll_id`, `option_text`, `sort_order`) '
                    . 'VALUES (:option_id, :poll_id, :option_text, :sort_order)',
                    [
                        'option_id' => $option->id()->value(),
                        'poll_id' => $poll->id()->value(),
                        'option_text' => $option->text(),
                        'sort_order' => $option->sortOrder(),
                    ],
                ));
            }
        });
    }

    public function castVote(
        EntityId $pollId,
        EntityId $userId,
        array $optionIds,
        DateTimeImmutable $at,
    ): void {
        PollId::assert($pollId);
        UserId::assert($userId);
        $at = self::utc($at);

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $pollId,
            $userId,
            $optionIds,
            $at,
        ): void {
            $row = $database->fetchOne(new CompiledQuery(
                $this->selectPollSql() . ' WHERE `poll_id` = :poll_id FOR UPDATE',
                ['poll_id' => $pollId->value()],
                true,
            ));
            if ($row === null) {
                throw new PollOperationException('Poll is not available.');
            }
            $poll = $this->hydrate($row, $this->loadOptions($pollId, $database));
            $selected = $poll->validateSelection($optionIds);

            $voterCount = (int) $database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM `forwext_poll_votes` WHERE `poll_id` = :poll_id',
                ['poll_id' => $pollId->value()],
                true,
            ));
            if ($poll->isClosed($at, $voterCount)) {
                throw new PollOperationException('Poll is closed.');
            }

            $existing = $database->fetchOne(new CompiledQuery(
                'SELECT `vote_id` FROM `forwext_poll_votes` '
                . 'WHERE `poll_id` = :poll_id AND `user_id` = :user_id LIMIT 1',
                ['poll_id' => $pollId->value(), 'user_id' => $userId->value()],
                true,
            ));

            if ($existing !== null) {
                $voteId = PollVoteId::fromStored((string) $existing['vote_id']);
                $currentRows = $database->fetchAll(new CompiledQuery(
                    'SELECT `option_id` FROM `forwext_poll_vote_choices` '
                    . 'WHERE `vote_id` = :vote_id ORDER BY `option_id` ASC',
                    ['vote_id' => $voteId->value()],
                    true,
                ));
                $current = array_map(static fn (array $choice): string => (string) $choice['option_id'], $currentRows);
                $next = array_map(static fn (EntityId $optionId): string => $optionId->value(), $selected);
                sort($current, SORT_STRING);
                sort($next, SORT_STRING);
                if ($current === $next) {
                    return;
                }
                if (!$poll->canChangeVote()) {
                    throw new PollOperationException('Vote changes are disabled for this poll.');
                }
                $database->execute(new CompiledQuery(
                    'DELETE FROM `forwext_poll_vote_choices` WHERE `vote_id` = :vote_id',
                    ['vote_id' => $voteId->value()],
                ));
                $database->execute(new CompiledQuery(
                    'UPDATE `forwext_poll_votes` SET `updated_at_utc` = :updated_at '
                    . 'WHERE `vote_id` = :vote_id',
                    ['updated_at' => self::format($at), 'vote_id' => $voteId->value()],
                ));
            } else {
                if ($poll->maxVoters() !== null && $voterCount >= $poll->maxVoters()) {
                    throw new PollOperationException('Poll participant limit has been reached.');
                }
                $voteId = PollVoteId::generate();
                $affected = $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_poll_votes` '
                    . '(`vote_id`, `poll_id`, `user_id`, `created_at_utc`, `updated_at_utc`) '
                    . 'VALUES (:vote_id, :poll_id, :user_id, :created_at, :updated_at)',
                    [
                        'vote_id' => $voteId->value(),
                        'poll_id' => $pollId->value(),
                        'user_id' => $userId->value(),
                        'created_at' => self::format($at),
                        'updated_at' => self::format($at),
                    ],
                ));
                if ($affected !== 1) {
                    throw new PollOperationException('Vote could not be recorded.');
                }
            }

            foreach ($selected as $optionId) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_poll_vote_choices` (`vote_id`, `option_id`) '
                    . 'VALUES (:vote_id, :option_id)',
                    ['vote_id' => $voteId->value(), 'option_id' => $optionId->value()],
                ));
            }
        });
    }

    public function hasVoted(EntityId $pollId, EntityId $userId): bool
    {
        PollId::assert($pollId);
        UserId::assert($userId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_poll_votes` '
            . 'WHERE `poll_id` = :poll_id AND `user_id` = :user_id',
            ['poll_id' => $pollId->value(), 'user_id' => $userId->value()],
        )) > 0;
    }

    public function voterCount(EntityId $pollId): int
    {
        PollId::assert($pollId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_poll_votes` WHERE `poll_id` = :poll_id',
            ['poll_id' => $pollId->value()],
        ));
    }

    public function results(Poll $poll, DateTimeImmutable $at, bool $includeVoters): PollResults
    {
        $counts = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT `o`.`option_id`, COUNT(`c`.`vote_id`) AS `vote_count` '
            . 'FROM `forwext_poll_options` `o` '
            . 'LEFT JOIN `forwext_poll_vote_choices` `c` ON `c`.`option_id` = `o`.`option_id` '
            . 'WHERE `o`.`poll_id` = :poll_id GROUP BY `o`.`option_id`',
            ['poll_id' => $poll->id()->value()],
        )) as $row) {
            $counts[(string) $row['option_id']] = (int) $row['vote_count'];
        }

        $voters = [];
        if ($includeVoters && $poll->voterVisibility() === PollVoterVisibility::Open) {
            foreach ($this->database->fetchAll(new CompiledQuery(
                'SELECT `c`.`option_id`, `v`.`user_id` FROM `forwext_poll_vote_choices` `c` '
                . 'INNER JOIN `forwext_poll_votes` `v` ON `v`.`vote_id` = `c`.`vote_id` '
                . 'WHERE `v`.`poll_id` = :poll_id ORDER BY `c`.`option_id`, `v`.`created_at_utc`, `v`.`vote_id`',
                ['poll_id' => $poll->id()->value()],
            )) as $row) {
                $optionId = (string) $row['option_id'];
                $voters[$optionId][] = ($row['user_id'] ?? null) === null
                    ? null
                    : UserId::fromStored((string) $row['user_id']);
            }
        }

        $totalVoters = $this->voterCount($poll->id());
        $options = [];
        foreach ($poll->options() as $option) {
            $id = $option->id()->value();
            $options[] = new PollOptionResult($option, $counts[$id] ?? 0, $voters[$id] ?? []);
        }

        return new PollResults(
            $poll->id(),
            $totalVoters,
            $poll->isClosed(self::utc($at), $totalVoters),
            $options,
        );
    }

    public function close(EntityId $pollId, DateTimeImmutable $at): void
    {
        PollId::assert($pollId);
        $at = self::utc($at);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($pollId, $at): void {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT `poll_id`, `closed_at_utc` FROM `forwext_polls` '
                . 'WHERE `poll_id` = :poll_id FOR UPDATE',
                ['poll_id' => $pollId->value()],
                true,
            ));
            if ($row === null) {
                throw new PollOperationException('Poll is not available.');
            }
            if (($row['closed_at_utc'] ?? null) !== null) {
                return;
            }
            $database->execute(new CompiledQuery(
                'UPDATE `forwext_polls` SET `closed_at_utc` = :closed_at WHERE `poll_id` = :poll_id',
                ['closed_at' => self::format($at), 'poll_id' => $pollId->value()],
            ));
        });
    }

    /** @return list<PollOption> */
    private function loadOptions(EntityId $pollId, ?TransactionalQueryExecutor $database = null): array
    {
        $database ??= $this->database;
        return array_map(
            static fn (array $row): PollOption => new PollOption(
                PollOptionId::fromStored((string) $row['option_id']),
                (string) $row['option_text'],
                (int) $row['sort_order'],
            ),
            $database->fetchAll(new CompiledQuery(
                'SELECT `option_id`, `option_text`, `sort_order` FROM `forwext_poll_options` '
                . 'WHERE `poll_id` = :poll_id ORDER BY `sort_order`, `option_id`',
                ['poll_id' => $pollId->value()],
            )),
        );
    }

    private function selectPollSql(): string
    {
        return 'SELECT `poll_id`, `thread_id`, `creator_user_id`, `question`, `selection_mode`, '
            . '`max_selections`, `change_vote`, `voter_visibility`, `result_visibility`, '
            . '`closes_at_utc`, `max_voters`, `closed_at_utc`, `created_at_utc` FROM `forwext_polls`';
    }

    /** @param array<string, mixed> $row @param list<PollOption> $options */
    private function hydrate(array $row, array $options): Poll
    {
        foreach ([
            'poll_id', 'thread_id', 'question', 'selection_mode', 'max_selections', 'change_vote',
            'voter_visibility', 'result_visibility', 'created_at_utc',
        ] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new RuntimeException('Stored poll row is missing required data.');
            }
        }

        return Poll::hydrate(
            PollId::fromStored((string) $row['poll_id']),
            ThreadId::fromStored((string) $row['thread_id']),
            ($row['creator_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['creator_user_id']),
            PollQuestion::fromString((string) $row['question']),
            PollSelectionMode::from((string) $row['selection_mode']),
            (int) $row['max_selections'],
            (bool) $row['change_vote'],
            PollVoterVisibility::from((string) $row['voter_visibility']),
            PollResultVisibility::from((string) $row['result_visibility']),
            ($row['closes_at_utc'] ?? null) === null ? null : self::parse((string) $row['closes_at_utc']),
            ($row['max_voters'] ?? null) === null ? null : (int) $row['max_voters'],
            $options,
            self::parse((string) $row['created_at_utc']),
            ($row['closed_at_utc'] ?? null) === null ? null : self::parse((string) $row['closed_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return self::utc($value)->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored poll timestamp is invalid.');
        }
        return $parsed;
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
