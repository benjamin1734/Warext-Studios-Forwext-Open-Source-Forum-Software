<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Social\Interaction;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Social\Interaction\DatabaseSocialRelationshipReader;
use PHPUnit\Framework\TestCase;

final class DatabaseSocialRelationshipReaderTest extends TestCase
{
    public function testFollowingHydratesOnlyActiveUserRelationshipRows(): void
    {
        $database = new RelationshipRecordingDatabase([[
            'user_id' => str_repeat('2', 32),
            'username' => 'Target_User',
            'username_key' => 'target_user',
            'created_at_utc' => '2026-09-27 18:00:00.000000',
        ]]);
        $reader = new DatabaseSocialRelationshipReader($database);

        $rows = $reader->following(EntityId::fromString(str_repeat('1', 32)), 25);

        self::assertCount(1, $rows);
        self::assertSame('Target_User', $rows[0]->username->display());
        self::assertSame(str_repeat('2', 32), $rows[0]->userId->value());
        self::assertNotNull($database->lastQuery);
        self::assertStringContainsString('forwext_user_follows', $database->lastQuery->sql);
        self::assertStringContainsString("u.`status` = 'active'", $database->lastQuery->sql);
        self::assertStringContainsString('LIMIT 25', $database->lastQuery->sql);
        self::assertSame(str_repeat('1', 32), $database->lastQuery->parameters['user_id'] ?? null);
    }

    public function testFollowersAndIgnoredUseTheirExpectedRelationshipDirections(): void
    {
        $database = new RelationshipRecordingDatabase([]);
        $reader = new DatabaseSocialRelationshipReader($database);
        $user = EntityId::fromString(str_repeat('1', 32));

        $reader->followers($user);
        self::assertNotNull($database->lastQuery);
        self::assertStringContainsString('f.`followed_user_id` = :user_id', $database->lastQuery->sql);
        self::assertStringContainsString('f.`follower_user_id`', $database->lastQuery->sql);

        $reader->ignored($user);
        self::assertNotNull($database->lastQuery);
        self::assertStringContainsString('forwext_user_ignores', $database->lastQuery->sql);
        self::assertStringContainsString('i.`ignored_user_id`', $database->lastQuery->sql);
    }
}

final class RelationshipRecordingDatabase implements QueryExecutor
{
    public ?CompiledQuery $lastQuery = null;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->lastQuery = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->lastQuery = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->lastQuery = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->lastQuery = $query;
        return null;
    }
}
