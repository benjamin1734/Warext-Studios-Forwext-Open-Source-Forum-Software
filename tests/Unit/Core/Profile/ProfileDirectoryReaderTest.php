<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Profile\ProfileDirectoryReader;
use PHPUnit\Framework\TestCase;

final class ProfileDirectoryReaderTest extends TestCase
{
    public function testStaffDirectoryUsesStaffRoleAndPublicProfileFilters(): void
    {
        $database = new ProfileDirectoryRecordingDatabase(
            rows: [[
                'user_id' => str_repeat('a', 32),
                'username' => 'staff-user',
                'created_at_utc' => '2026-10-04 10:00:00.000000',
                'avatar_path' => null,
            ]],
            values: [1],
        );
        $reader = new ProfileDirectoryReader($database);

        $members = $reader->staffPublic(24, 0);
        $count = $reader->countStaffPublic();

        self::assertCount(1, $members);
        self::assertSame('staff-user', $members[0]['username']);
        self::assertFalse($members[0]['has_avatar']);
        self::assertSame(1, $count);
        self::assertCount(2, $database->queries);

        foreach ($database->queries as $query) {
            self::assertStringContainsString("u.`status` = 'active'", $query->sql);
            self::assertStringContainsString("COALESCE(p.`profile_visibility`, 'public') = 'public'", $query->sql);
            self::assertStringContainsString('`forwext_user_role_assignments`', $query->sql);
            self::assertStringContainsString('`forwext_roles`', $query->sql);
            self::assertStringContainsString("r.`kind` = 'staff'", $query->sql);
            self::assertStringNotContainsString('r.`name`', $query->sql);
        }

        self::assertStringContainsString('LIMIT 24 OFFSET 0', $database->queries[0]->sql);
    }
}

final class ProfileDirectoryRecordingDatabase implements QueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    /** @param list<array<string,mixed>> $rows @param list<mixed> $values */
    public function __construct(
        private array $rows = [],
        private array $values = [],
    ) {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->queries[] = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->queries[] = $query;
        return array_shift($this->values);
    }
}
