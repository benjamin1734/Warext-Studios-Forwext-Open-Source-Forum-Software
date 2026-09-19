<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseGiveawayRepository implements GiveawayRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $giveawayId): ?Giveaway
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_giveaways WHERE giveaway_id=:giveaway_id LIMIT 1',
            ['giveaway_id'=>$giveawayId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function list(?EntityId $ownerUserId = null, bool $publicOnly = true, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Giveaway listing limit is invalid.');
        }
        $where = [];
        $parameters = [];
        if ($ownerUserId !== null) {
            UserId::assert($ownerUserId);
            $where[] = 'owner_user_id=:owner_user_id';
            $parameters['owner_user_id'] = $ownerUserId->value();
        }
        if ($publicOnly) {
            $where[] = "state IN ('scheduled','open','closed')";
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_giveaways'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY starts_at_utc DESC,giveaway_id DESC LIMIT ' . $limit,
            $parameters,
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function save(Giveaway $giveaway): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_giveaways '
            . '(giveaway_id,owner_user_id,slug,title,description,prize_title,prize_description,prize_quantity,'
            . 'participation_terms,starts_at_utc,ends_at_utc,entries_per_user,max_participants,state,'
            . 'created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:owner,:slug,:title,:description,:prize_title,:prize_description,:prize_quantity,'
            . ':terms,:starts,:ends,:entries_per_user,:max_participants,:state,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE slug=VALUES(slug),title=VALUES(title),description=VALUES(description),'
            . 'prize_title=VALUES(prize_title),prize_description=VALUES(prize_description),'
            . 'prize_quantity=VALUES(prize_quantity),participation_terms=VALUES(participation_terms),'
            . 'starts_at_utc=VALUES(starts_at_utc),ends_at_utc=VALUES(ends_at_utc),'
            . 'entries_per_user=VALUES(entries_per_user),max_participants=VALUES(max_participants),'
            . 'state=VALUES(state),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$giveaway->giveawayId->value(),
                'owner'=>$giveaway->ownerUserId->value(),
                'slug'=>$giveaway->slug,
                'title'=>$giveaway->title,
                'description'=>$giveaway->description,
                'prize_title'=>$giveaway->prize->title,
                'prize_description'=>$giveaway->prize->description,
                'prize_quantity'=>$giveaway->prize->quantity,
                'terms'=>$giveaway->participationTerms,
                'starts'=>self::format($giveaway->startsAt),
                'ends'=>self::format($giveaway->endsAt),
                'entries_per_user'=>$giveaway->entriesPerUser,
                'max_participants'=>$giveaway->maxParticipants,
                'state'=>$giveaway->state->value,
                'created'=>self::format($giveaway->createdAt),
                'updated'=>self::format($giveaway->updatedAt),
            ],
        ));
    }

    public function dueTransitions(DateTimeImmutable $at, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Giveaway lifecycle limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT * FROM forwext_giveaways WHERE "
            . "(state='scheduled' AND starts_at_utc<=:now) OR (state='open' AND ends_at_utc<=:now) "
            . 'ORDER BY CASE WHEN state=\'open\' THEN ends_at_utc ELSE starts_at_utc END,giveaway_id LIMIT ' . $limit,
            ['now'=>self::format($at)],
        ));
        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Giveaway
    {
        try {
            $state = GiveawayState::from((string) $row['state']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored giveaway state is invalid.', previous:$exception);
        }
        return new Giveaway(
            EntityId::fromString((string) $row['giveaway_id']),
            UserId::fromStored((string) $row['owner_user_id']),
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['description'],
            new GiveawayPrize(
                (string) $row['prize_title'],
                (string) $row['prize_description'],
                (int) $row['prize_quantity'],
            ),
            (string) $row['participation_terms'],
            self::parse((string) $row['starts_at_utc']),
            self::parse((string) $row['ends_at_utc']),
            (int) $row['entries_per_user'],
            isset($row['max_participants']) ? (int) $row['max_participants'] : null,
            $state,
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) return $date;
        }
        throw new RuntimeException('Stored giveaway timestamp is invalid.');
    }
}
