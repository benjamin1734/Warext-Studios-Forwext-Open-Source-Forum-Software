<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class DatabaseUserAccessAssignmentProviderTest extends TestCase
{
    public function testExistingUserWithoutPrimaryGroupGetsNarrowCompatibilityAssignment(): void
    {
        $database = new AccessAssignmentRecordingDatabase();
        $database->fetchOneQueue = [null];
        $database->fetchValueQueue = [1];
        $provider = new DatabaseUserAccessAssignmentProvider($database);

        $assignment = $provider->find(EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

        self::assertNotNull($assignment);
        self::assertSame(
            DatabaseUserAccessAssignmentProvider::UNASSIGNED_GROUP_ID,
            $assignment->primaryGroupId()->value(),
        );
        self::assertSame([], $assignment->secondaryGroupIds());
        self::assertSame([], $assignment->roleIds());
    }

    public function testUnknownUserDoesNotReceiveCompatibilityAssignment(): void
    {
        $database = new AccessAssignmentRecordingDatabase();
        $database->fetchOneQueue = [null];
        $database->fetchValueQueue = [0];

        $assignment = (new DatabaseUserAccessAssignmentProvider($database))
            ->find(EntityId::fromString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));

        self::assertNull($assignment);
    }

    public function testPersistedPrimarySecondaryAndRoleAssignmentsAreHydratedDeterministically(): void
    {
        $database = new AccessAssignmentRecordingDatabase();
        $database->fetchOneQueue = [['group_id' => 'group:member']];
        $database->fetchAllQueue = [
            [['group_id' => 'group:verified'], ['group_id' => 'group:vip']],
            [['role_id' => 'role:moderator'], ['role_id' => 'role:staff']],
        ];

        $assignment = (new DatabaseUserAccessAssignmentProvider($database))
            ->find(EntityId::fromString('cccccccccccccccccccccccccccccccc'));

        self::assertNotNull($assignment);
        self::assertSame('group:member', $assignment->primaryGroupId()->value());
        self::assertSame(
            ['group:verified', 'group:vip'],
            array_map(static fn (EntityId $id): string => $id->value(), $assignment->secondaryGroupIds()),
        );
        self::assertSame(
            ['role:moderator', 'role:staff'],
            array_map(static fn (EntityId $id): string => $id->value(), $assignment->roleIds()),
        );
        self::assertStringContainsString('ORDER BY `group_id`', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('ORDER BY `role_id`', $database->fetchAllQueries[1]->sql);
    }
}

final class AccessAssignmentRecordingDatabase implements QueryExecutor
{
    /** @var list<array<string, mixed>|null> */
    public array $fetchOneQueue = [];

    /** @var list<mixed> */
    public array $fetchValueQueue = [];

    /** @var list<list<array<string, mixed>>> */
    public array $fetchAllQueue = [];

    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return array_shift($this->fetchOneQueue);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllQueue) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return array_shift($this->fetchValueQueue);
    }
}
