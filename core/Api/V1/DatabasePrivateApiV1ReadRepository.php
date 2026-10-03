<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabasePrivateApiV1ReadRepository implements PrivateApiV1ReadRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function conversations(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        UserId::assert($userId);
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT c.conversation_id,op.user_id AS other_user_id,'
            . 'COALESCE(u.username,\'Silinmiş kullanıcı\') AS other_username,'
            . 'COALESCE(lm.body,\'\') AS preview,c.updated_at_utc,'
            . '(SELECT COUNT(*) FROM forwext_direct_messages um '
            . 'WHERE um.conversation_id=c.conversation_id '
            . 'AND (um.author_user_id IS NULL OR um.author_user_id<>:unread_actor_id) '
            . 'AND (p.last_read_at_utc IS NULL OR um.created_at_utc>p.last_read_at_utc)) AS unread_count '
            . 'FROM forwext_direct_conversation_participants p '
            . 'INNER JOIN forwext_direct_conversations c ON c.conversation_id=p.conversation_id '
            . 'INNER JOIN forwext_direct_conversation_participants op '
            . 'ON op.conversation_id=c.conversation_id AND op.user_id<>p.user_id '
            . 'LEFT JOIN forwext_users u ON u.user_id=op.user_id '
            . 'LEFT JOIN forwext_direct_messages lm ON lm.message_id=c.last_message_id '
            . 'WHERE p.user_id=:actor_id '
            . 'ORDER BY c.updated_at_utc DESC,c.conversation_id DESC '
            . 'LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            [
                'actor_id'=>$userId->value(),
                'unread_actor_id'=>$userId->value(),
            ],
        ));

        return self::pageResult($rows, $page, $perPage, static fn (array $row): array => [
            'id'=>(string) $row['conversation_id'],
            'other_user_id'=>(string) $row['other_user_id'],
            'other_username'=>(string) $row['other_username'],
            'preview'=>(string) $row['preview'],
            'unread_count'=>max(0, (int) $row['unread_count']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
        ]);
    }

    public function notifications(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        UserId::assert($userId);
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT notification_id,type_key,category_key,title,body,action_path,occurrences,'
            . 'created_at_utc,updated_at_utc,read_at_utc FROM forwext_notifications '
            . 'WHERE recipient_user_id=:user_id AND in_app_visible=1 '
            . 'ORDER BY updated_at_utc DESC,notification_id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            ['user_id'=>$userId->value()],
        ));

        return self::pageResult($rows, $page, $perPage, static fn (array $row): array => [
            'id'=>(string) $row['notification_id'],
            'type'=>(string) $row['type_key'],
            'category'=>(string) $row['category_key'],
            'title'=>(string) $row['title'],
            'body'=>(string) $row['body'],
            'action_path'=>is_string($row['action_path'] ?? null) ? $row['action_path'] : null,
            'occurrences'=>(int) $row['occurrences'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
            'read_at'=>self::timestampNullable($row['read_at_utc'] ?? null),
        ]);
    }

    public function supportTickets(EntityId $userId, int $page, int $perPage): ApiV1Page
    {
        UserId::assert($userId);
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT ticket_id,category_key,subject,priority,status,resolved_at_utc,closed_at_utc,'
            . 'created_at_utc,updated_at_utc FROM forwext_support_tickets '
            . 'WHERE requester_user_id=:user_id ORDER BY updated_at_utc DESC,ticket_id DESC '
            . 'LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            ['user_id'=>$userId->value()],
        ));

        return self::pageResult($rows, $page, $perPage, static fn (array $row): array => [
            'id'=>(string) $row['ticket_id'],
            'category'=>(string) $row['category_key'],
            'subject'=>(string) $row['subject'],
            'priority'=>(string) $row['priority'],
            'status'=>(string) $row['status'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
            'resolved_at'=>self::timestampNullable($row['resolved_at_utc'] ?? null),
            'closed_at'=>self::timestampNullable($row['closed_at_utc'] ?? null),
        ]);
    }

    /** @return array{0:int,1:int} */
    private static function page(int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('API v1 pagination is invalid.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > 10000000) {
            throw new InvalidArgumentException('API v1 pagination offset is too large.');
        }

        return [$perPage, $offset];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $mapper
     */
    private static function pageResult(array $rows, int $page, int $perPage, callable $mapper): ApiV1Page
    {
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        return new ApiV1Page(array_map($mapper, $rows), $page, $perPage, $hasMore);
    }

    private static function timestamp(string $stored): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $stored, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('API v1 private read model encountered an invalid timestamp.');
        }

        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function timestampNullable(mixed $stored): ?string
    {
        return is_string($stored) && $stored !== '' ? self::timestamp($stored) : null;
    }
}
