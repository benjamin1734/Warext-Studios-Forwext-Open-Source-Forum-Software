<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseGiveawayDrawRepository implements GiveawayDrawRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function latest(EntityId $giveawayId): ?GiveawayDraw
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_giveaway_draws WHERE giveaway_id=:giveaway_id '
            . 'ORDER BY sequence DESC LIMIT 1',
            ['giveaway_id'=>$giveawayId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function history(EntityId $giveawayId): array
    {
        return array_map(
            $this->hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_giveaway_draws WHERE giveaway_id=:giveaway_id ORDER BY sequence',
                ['giveaway_id'=>$giveawayId->value()],
            )),
        );
    }

    public function save(GiveawayDraw $draw, array $population): void
    {
        $this->database->transaction(function () use ($draw, $population): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_giveaway_draws '
                . '(draw_id,giveaway_id,sequence,kind,parent_draw_id,redraw_reason,algorithm,seed_hex,population_hash,'
                . 'participant_count,total_weight,selected_ticket,winner_user_id,winner_entry_id,winner_entry_weight,'
                . 'created_by_user_id,proof_hash,created_at_utc) '
                . 'VALUES (:draw,:giveaway,:sequence,:kind,:parent,:reason,:algorithm,:seed,:population,:participants,'
                . ':weight,:ticket,:winner_user,:winner_entry,:winner_weight,:actor,:proof,:created)',
                [
                    'draw'=>$draw->drawId->value(),
                    'giveaway'=>$draw->giveawayId->value(),
                    'sequence'=>$draw->sequence,
                    'kind'=>$draw->kind->value,
                    'parent'=>$draw->parentDrawId?->value(),
                    'reason'=>$draw->redrawReason,
                    'algorithm'=>GiveawayDraw::ALGORITHM,
                    'seed'=>$draw->seedHex,
                    'population'=>$draw->populationHash,
                    'participants'=>$draw->participantCount,
                    'weight'=>$draw->totalWeight,
                    'ticket'=>$draw->selectedTicket,
                    'winner_user'=>$draw->winnerUserId->value(),
                    'winner_entry'=>$draw->winnerEntryId->value(),
                    'winner_weight'=>$draw->winnerEntryWeight,
                    'actor'=>$draw->createdByUserId?->value(),
                    'proof'=>$draw->proofHash,
                    'created'=>self::format($draw->createdAt),
                ],
            ));

            foreach (array_values($population) as $index=>$candidate) {
                if (!$candidate instanceof GiveawayDrawCandidate) {
                    throw new RuntimeException('Giveaway draw population contains an invalid candidate.');
                }
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_giveaway_draw_population '
                    . '(draw_id,ordinal,entry_id,user_id,entry_weight) '
                    . 'VALUES (:draw,:ordinal,:entry,:user,:weight)',
                    [
                        'draw'=>$draw->drawId->value(),
                        'ordinal'=>$index + 1,
                        'entry'=>$candidate->entryId->value(),
                        'user'=>$candidate->userId->value(),
                        'weight'=>$candidate->weight,
                    ],
                ));
            }
        });
    }

    public function population(EntityId $drawId): array
    {
        return array_map(
            static fn (array $row): GiveawayDrawCandidate => new GiveawayDrawCandidate(
                EntityId::fromString((string) $row['entry_id']),
                UserId::fromStored((string) $row['user_id']),
                (int) $row['entry_weight'],
            ),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT entry_id,user_id,entry_weight FROM forwext_giveaway_draw_population '
                . 'WHERE draw_id=:draw_id ORDER BY ordinal',
                ['draw_id'=>$drawId->value()],
            )),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): GiveawayDraw
    {
        if ((string) $row['algorithm'] !== GiveawayDraw::ALGORITHM) {
            throw new RuntimeException('Stored giveaway draw algorithm is unsupported.');
        }
        try {
            $kind = GiveawayDrawKind::from((string) $row['kind']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored giveaway draw kind is invalid.', previous:$exception);
        }

        return new GiveawayDraw(
            EntityId::fromString((string) $row['draw_id']),
            EntityId::fromString((string) $row['giveaway_id']),
            (int) $row['sequence'],
            $kind,
            isset($row['parent_draw_id']) && is_string($row['parent_draw_id'])
                ? EntityId::fromString($row['parent_draw_id']) : null,
            isset($row['redraw_reason']) && is_string($row['redraw_reason']) ? $row['redraw_reason'] : null,
            (string) $row['seed_hex'],
            (string) $row['population_hash'],
            (int) $row['participant_count'],
            (int) $row['total_weight'],
            (int) $row['selected_ticket'],
            UserId::fromStored((string) $row['winner_user_id']),
            EntityId::fromString((string) $row['winner_entry_id']),
            (int) $row['winner_entry_weight'],
            isset($row['created_by_user_id']) && is_string($row['created_by_user_id'])
                ? UserId::fromStored($row['created_by_user_id']) : null,
            self::parse((string) $row['created_at_utc']),
            (string) $row['proof_hash'],
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
        throw new RuntimeException('Stored giveaway draw timestamp is invalid.');
    }
}
