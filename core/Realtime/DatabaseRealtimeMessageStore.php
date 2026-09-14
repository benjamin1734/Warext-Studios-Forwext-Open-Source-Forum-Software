<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class DatabaseRealtimeMessageStore implements RealtimeMessageStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function append(RealtimeMessage $message): RealtimeEnvelope
    {
        return $this->database->transaction(function () use ($message): RealtimeEnvelope {
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_realtime_messages` '
                . '(`channel_name`, `event_name`, `payload`, `created_at_utc`) '
                . 'VALUES (:channel_name, :event_name, :payload, :created_at_utc)',
                [
                    'channel_name' => $message->channel,
                    'event_name' => $message->event,
                    'payload' => $message->payload,
                    'created_at_utc' => self::formatDate($message->createdAt),
                ],
            ));
            if ($affected !== 1) {
                throw new RealtimeException('Realtime append did not insert exactly one row.');
            }

            $sequence = $this->database->fetchValue(new CompiledQuery('SELECT LAST_INSERT_ID()'));
            if ((!is_int($sequence) && !is_string($sequence)) || !ctype_digit((string) $sequence) || (int) $sequence < 1) {
                throw new RealtimeException('Unable to obtain realtime message sequence.');
            }

            return new RealtimeEnvelope((int) $sequence, $message);
        });
    }

    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $channel) !== 1
            || $afterSequence < 0
            || $limit < 1
            || $limit > 500
        ) {
            throw new RealtimeException('Realtime cursor/channel/limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `sequence_id`, `channel_name`, `event_name`, `payload`, `created_at_utc` '
            . 'FROM `forwext_realtime_messages` '
            . 'WHERE `channel_name` = :channel_name AND `sequence_id` > :after_sequence '
            . 'ORDER BY `sequence_id` ASC LIMIT ' . $limit,
            [
                'channel_name' => $channel,
                'after_sequence' => $afterSequence,
            ],
        ));

        $messages = [];
        foreach ($rows as $row) {
            foreach (['channel_name', 'event_name', 'payload', 'created_at_utc'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    throw new RealtimeException('Realtime database row is malformed.');
                }
            }
            $sequence = $row['sequence_id'] ?? null;
            if ((!is_int($sequence) && !is_string($sequence)) || !ctype_digit((string) $sequence) || (int) $sequence < 1) {
                throw new RealtimeException('Realtime database sequence is invalid.');
            }
            $createdAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $row['created_at_utc'],
                new DateTimeZone('UTC'),
            );
            if (!$createdAt instanceof DateTimeImmutable) {
                throw new RealtimeException('Realtime database timestamp is invalid.');
            }

            $messages[] = new RealtimeEnvelope(
                (int) $sequence,
                new RealtimeMessage(
                    $row['channel_name'],
                    $row['event_name'],
                    $row['payload'],
                    $createdAt,
                ),
            );
        }

        return $messages;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
