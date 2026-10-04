<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Profile\ProfileDirectoryReader;
use PHPUnit\Framework\TestCase;

final class ProfileDirectoryReaderStaffTest extends TestCase
{
    public function testStaffSearchUsesAuthoritativeStaffAssignmentsAndPublicProfileBoundary(): void
    {
        $database = new CapturingDirectoryQueryExecutor();
        $database->rows = [[
            'user_id' => '0123456789abcdef0123456789abcdef',
            'username' => 'Ada',
            'created_at_utc' => '2026-10-04 10:00:00.000000',
            'avatar_path' => 'avatars/ada.webp',
        ]];

        $reader = new ProfileDirectoryReader($database);
        $members = $reader->searchPublicStaff('Ad', 10, 20);

        self::assertCount(1, $members);
        self::assertSame('Ada', $members[0]['username']);
        self::assertTrue($members[0]['has_avatar']);

        $query = self::assertQuery($database->lastFetchAll);
        self::assertStringContainsString('forwext_user_role_assignments', $query->sql);
        self::assertStringContainsString('forwext_roles', $query->sql);
        self::assertStringContainsString("r.`kind` = 'staff'", $query->sql);
        self::assertStringContainsString("u.`status` = 'active'", $query->sql);
        self::assertStringContainsString("COALESCE(p.`profile_visibility`, 'public') = 'public'", $query->sql);
        self::assertStringContainsString('LIMIT 10 OFFSET 20', $query->sql);
        self::assertSame('%Ad%', $query->parameters['username_query'] ?? null);
    }

    public function testStaffCountUsesSameVisibilityAndRoleBoundary(): void
    {
        $database = new CapturingDirectoryQueryExecutor();
        $database->value = 4;

        $reader = new ProfileDirectoryReader($database);

        self::assertSame(4, $reader->countPublicStaff('Mod'));

        $query = self::assertQuery($database->lastFetchValue);
        self::assertStringContainsString('forwext_user_role_assignments', $query->sql);
        self::assertStringContainsString("r.`kind` = 'staff'", $query->sql);
        self::assertStringContainsString("COALESCE(p.`profile_visibility`, 'public') = 'public'", $query->sql);
        self::assertSame('%Mod%', $query->parameters['username_query'] ?? null);
    }

    private static function assertQuery(?CompiledQuery $query): CompiledQuery
    {
        self::assertInstanceOf(CompiledQuery::class, $query);
        return $query;
    }
}

final class CapturingDirectoryQueryExecutor implements QueryExecutor
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    public mixed $value = 0;
    public ?CompiledQuery $lastFetchAll = null;
    public ?CompiledQuery $lastFetchValue = null;

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->lastFetchAll = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->lastFetchValue = $query;
        return $this->value;
    }
}
