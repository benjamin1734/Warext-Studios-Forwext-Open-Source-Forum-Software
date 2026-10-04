<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateCommunityGroups;
use PHPUnit\Framework\TestCase;

final class CommunityGroupsMigrationTest extends TestCase
{
    public function testMigrationCreatesGroupMembershipModuleAndPermissionContracts(): void
    {
        $database = new CommunityGroupsMigrationRecordingDatabase();
        $database->fetchValues = [1];
        $migration = new CreateCommunityGroups();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261005213000_community_groups', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->queries,
        ));
        $parameters = [];
        foreach ($database->queries as $query) {
            foreach ($query->parameters as $value) {
                if (is_string($value)) {
                    $parameters[] = $value;
                }
            }
        }
        $contract = $sql . "\n" . implode("\n", $parameters);

        foreach ([
            'forwext_groups',
            'forwext_group_members',
            'uq_forwext_group_slug',
            'fk_forwext_group_owner',
            'fk_forwext_group_member_group',
            'fk_forwext_group_member_user',
            'fk_forwext_group_member_actor',
            'group.view',
            'group.create',
            'group.join',
            'group.manage_own',
            'group.moderate_any',
            'forwext_first_party_modules',
            'groups',
        ] as $expected) {
            self::assertStringContainsString($expected, $contract);
        }
    }

    public function testVerificationRequiresSchemaPermissionsRulesModuleAndUniqueSlug(): void
    {
        $database = new CommunityGroupsMigrationRecordingDatabase();
        $database->fetchValues = [2, 4, 5, 25, 1, 1];

        self::assertTrue(
            (new CreateCommunityGroups())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class CommunityGroupsMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];
    /** @var list<mixed> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->queries[] = $query;
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->queries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
