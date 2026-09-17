<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseNotificationRealtimeReader implements NotificationRealtimeReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function latestSequence(string $channel): int
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $channel) !== 1) {
            throw new NotificationRealtimeException('Notification realtime channel is invalid.');
        }

        $value = $this->database->fetchValue(new CompiledQuery(
            'SELECT COALESCE(MAX(`sequence_id`), 0) FROM `forwext_realtime_messages` WHERE `channel_name` = :channel_name',
            ['channel_name' => $channel],
        ));

        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)) {
            throw new NotificationRealtimeException('Notification realtime cursor is invalid.');
        }

        return (int) $value;
    }

    public function visibleByIds(EntityId $recipientUserId, array $notificationIds): array
    {
        UserId::assert($recipientUserId);
        if ($notificationIds === []) {
            return [];
        }
        if (count($notificationIds) > 100) {
            throw new NotificationRealtimeException('Notification realtime lookup limit exceeded.');
        }

        $parameters = ['recipient_user_id' => $recipientUserId->value()];
        $placeholders = [];
        foreach (array_values($notificationIds) as $index => $id) {
            if (!$id instanceof EntityId) {
                throw new NotificationRealtimeException('Notification realtime lookup id is invalid.');
            }
            $name = 'notification_id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id->value();
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `notification_id`, `type_key`, `category_key`, `title`, `body`, `action_path`, '
            . '`occurrences`, `updated_at_utc`, `read_at_utc` FROM `forwext_notifications` '
            . 'WHERE `recipient_user_id` = :recipient_user_id AND `in_app_visible` = 1 '
            . 'AND `notification_id` IN (' . implode(',', $placeholders) . ')',
            $parameters,
        ));

        $result = [];
        foreach ($rows as $row) {
            foreach (['notification_id', 'type_key', 'category_key', 'title', 'body', 'updated_at_utc'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    throw new NotificationRealtimeException('Stored realtime notification row is malformed.');
                }
            }
            $actionPath = $row['action_path'] ?? null;
            $readAtRaw = $row['read_at_utc'] ?? null;
            if ($actionPath !== null && !is_string($actionPath)) {
                throw new NotificationRealtimeException('Stored notification action path is malformed.');
            }
            if ($readAtRaw !== null && !is_string($readAtRaw)) {
                throw new NotificationRealtimeException('Stored notification read timestamp is malformed.');
            }
            $occurrences = $row['occurrences'] ?? null;
            if ((!is_int($occurrences) && !is_string($occurrences)) || !ctype_digit((string) $occurrences) || (int) $occurrences < 1) {
                throw new NotificationRealtimeException('Stored notification occurrence count is malformed.');
            }

            $id = EntityId::fromString($row['notification_id']);
            $updatedAt = $this->parseDate($row['updated_at_utc']);
            $readAt = $readAtRaw === null ? null : $this->parseDate($readAtRaw);
            $result[$id->value()] = new RealtimeNotificationSnapshot(
                $id,
                $row['type_key'],
                $row['category_key'],
                $row['title'],
                $row['body'],
                $actionPath,
                (int) $occurrences,
                $updatedAt,
                $readAt,
            );
        }

        return $result;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new NotificationRealtimeException('Stored notification timestamp is invalid.');
        }
        return $date;
    }
}
