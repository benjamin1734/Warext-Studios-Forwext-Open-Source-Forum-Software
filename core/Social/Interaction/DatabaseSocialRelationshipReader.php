<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\Username;

final readonly class DatabaseSocialRelationshipReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    /** @return list<UserRelationshipEntry> */
    public function following(EntityId $userId, int $limit = 100): array
    {
        UserId::assert($userId);
        $this->assertLimit($limit);
        return $this->rows(
            'SELECT u.`user_id`, u.`username`, u.`username_key`, f.`created_at_utc` '
            . 'FROM `forwext_user_follows` f INNER JOIN `forwext_users` u ON u.`user_id` = f.`followed_user_id` '
            . "WHERE f.`follower_user_id` = :user_id AND u.`status` = 'active' "
            . 'ORDER BY f.`created_at_utc` DESC, u.`username_key` LIMIT ' . $limit,
            $userId,
        );
    }

    /** @return list<UserRelationshipEntry> */
    public function followers(EntityId $userId, int $limit = 100): array
    {
        UserId::assert($userId);
        $this->assertLimit($limit);
        return $this->rows(
            'SELECT u.`user_id`, u.`username`, u.`username_key`, f.`created_at_utc` '
            . 'FROM `forwext_user_follows` f INNER JOIN `forwext_users` u ON u.`user_id` = f.`follower_user_id` '
            . "WHERE f.`followed_user_id` = :user_id AND u.`status` = 'active' "
            . 'ORDER BY f.`created_at_utc` DESC, u.`username_key` LIMIT ' . $limit,
            $userId,
        );
    }

    /** @return list<UserRelationshipEntry> */
    public function ignored(EntityId $userId, int $limit = 100): array
    {
        UserId::assert($userId);
        $this->assertLimit($limit);
        return $this->rows(
            'SELECT u.`user_id`, u.`username`, u.`username_key`, i.`created_at_utc` '
            . 'FROM `forwext_user_ignores` i INNER JOIN `forwext_users` u ON u.`user_id` = i.`ignored_user_id` '
            . "WHERE i.`user_id` = :user_id AND u.`status` = 'active' "
            . 'ORDER BY i.`created_at_utc` DESC, u.`username_key` LIMIT ' . $limit,
            $userId,
        );
    }

    /** @return list<UserRelationshipEntry> */
    private function rows(string $sql, EntityId $userId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery($sql, ['user_id' => $userId->value()]));
        return array_map(
            fn (array $row): UserRelationshipEntry => new UserRelationshipEntry(
                UserId::fromStored((string) $row['user_id']),
                Username::fromStored((string) $row['username'], (string) $row['username_key']),
                $this->date((string) $row['created_at_utc']),
            ),
            $rows,
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new SocialInteractionException('Stored relationship timestamp is invalid.');
        }
        return $date;
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new SocialInteractionException('Relationship list limit is invalid.');
        }
    }
}
