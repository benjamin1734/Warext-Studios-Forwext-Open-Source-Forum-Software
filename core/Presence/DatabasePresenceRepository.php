<?php

declare(strict_types=1);

namespace Forwext\Core\Presence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabasePresenceRepository
{
    private const WRITE_THROTTLE_SECONDS = 60;

    public function __construct(private QueryExecutor $database)
    {
    }

    public function touch(EntityId $userId, DateTimeImmutable $now): void
    {
        UserId::assert($userId);
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $writeBefore = $now->sub(new DateInterval('PT' . self::WRITE_THROTTLE_SECONDS . 'S'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_presence` '
            . '(`user_id`, `last_seen_at_utc`, `visibility`, `updated_at_utc`) '
            . "VALUES (:user_id, :last_seen, 'members', :updated_at) "
            . 'ON DUPLICATE KEY UPDATE '
            . '`updated_at_utc` = IF(`last_seen_at_utc` <= :write_before, VALUES(`updated_at_utc`), `updated_at_utc`), '
            . '`last_seen_at_utc` = IF(`last_seen_at_utc` <= :write_before_2, VALUES(`last_seen_at_utc`), `last_seen_at_utc`)',
            [
                'user_id' => $userId->value(),
                'last_seen' => self::format($now),
                'updated_at' => self::format($now),
                'write_before' => self::format($writeBefore),
                'write_before_2' => self::format($writeBefore),
            ],
        ));
    }

    public function setVisibility(
        EntityId $userId,
        PresenceVisibility $visibility,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($userId);
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_presence` '
            . '(`user_id`, `last_seen_at_utc`, `visibility`, `updated_at_utc`) '
            . 'VALUES (:user_id, :last_seen, :visibility, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `visibility` = VALUES(`visibility`), '
            . '`updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'user_id' => $userId->value(),
                'last_seen' => self::format($now),
                'visibility' => $visibility->value,
                'updated_at' => self::format($now),
            ],
        ));
    }

    public function visibility(EntityId $userId): PresenceVisibility
    {
        UserId::assert($userId);
        $value = $this->database->fetchValue(new CompiledQuery(
            'SELECT `visibility` FROM `forwext_user_presence` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        if ($value === null) {
            return PresenceVisibility::Members;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Stored presence visibility is invalid.');
        }

        return PresenceVisibility::from($value);
    }

    /** @return list<OnlineUser> */
    public function online(bool $viewerAuthenticated, DateTimeImmutable $since, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Online user limit must be 1..100.');
        }

        $visibility = $viewerAuthenticated
            ? "`pr`.`visibility` IN ('public','members')"
            : "`pr`.`visibility` = 'public'";
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `u`.`username`, `pr`.`last_seen_at_utc` '
            . 'FROM `forwext_user_presence` `pr` '
            . 'INNER JOIN `forwext_users` `u` ON `u`.`user_id` = `pr`.`user_id` '
            . 'LEFT JOIN `forwext_user_profiles` `p` ON `p`.`user_id` = `u`.`user_id` '
            . "WHERE `u`.`status` = 'active' "
            . "AND COALESCE(`p`.`profile_visibility`, 'public') = 'public' "
            . 'AND `pr`.`last_seen_at_utc` >= :since AND ' . $visibility . ' '
            . 'ORDER BY `pr`.`last_seen_at_utc` DESC, `u`.`username_key` ASC LIMIT ' . $limit,
            ['since' => self::format($since)],
        ));

        return array_map(static function (array $row): OnlineUser {
            $username = $row['username'] ?? null;
            $lastSeenValue = $row['last_seen_at_utc'] ?? null;
            if (!is_string($username) || $username === '' || !is_string($lastSeenValue)) {
                throw new RuntimeException('Stored online user row is invalid.');
            }
            $lastSeen = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s.u',
                $lastSeenValue,
                new DateTimeZone('UTC'),
            );
            if (!$lastSeen instanceof DateTimeImmutable) {
                throw new RuntimeException('Stored presence timestamp is invalid.');
            }
            return new OnlineUser($username, $lastSeen);
        }, $rows);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
