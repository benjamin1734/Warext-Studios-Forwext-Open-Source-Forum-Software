<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateForumMetadataTables;
use PHPUnit\Framework\TestCase;

final class ForumMetadataMigrationTest extends TestCase
{
    public function testMigrationCreatesMetadataSchemaPermissionsAndStarterRules(): void
    {
        $database = new ForumMetadataMigrationRecordingDatabase();
        $migration = new CreateForumMetadataTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235957_forum_metadata', $migration->id()->value());
        self::assertCount(23, $database->executedQueries);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        foreach ([
            'forwext_prefix_groups',
            'forwext_thread_prefixes',
            'forwext_forum_prefix_groups',
            'forwext_thread_prefix_assignments',
            'forwext_tags',
            'forwext_thread_tags',
            'forwext_custom_fields',
            'forwext_forum_thread_fields',
            'forwext_thread_custom_field_values',
            'forwext_forum_custom_field_values',
            'forwext_forum_content_config',
        ] as $table) {
            self::assertStringContainsString($table, $sql);
        }
        self::assertStringContainsString('uq_forwext_tags_name', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
        self::assertStringContainsString('DEFAULT 0', $database->executedQueries[10]->sql);

        $templateEffects = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'])) {
                $templateEffects[
                    (string) $query->parameters['template_key'] . ':' . (string) $query->parameters['permission_key']
                ] = (string) $query->parameters['effect'];
            }
        }
        self::assertCount(10, $templateEffects);
        self::assertSame('allow', $templateEffects['member:forum.thread.edit_own']);
        self::assertSame('deny', $templateEffects['member:forum.thread.edit_any']);
        self::assertSame('allow', $templateEffects['moderator:forum.thread.edit_any']);
        self::assertSame('allow', $templateEffects['administrator:forum.thread.edit_any']);
    }

    public function testVerificationRequiresAllTablesIntegrityAndPermissionSeeds(): void
    {
        $database = new ForumMetadataMigrationRecordingDatabase();
        $database->fetchValues = [11, 1, 2, 10, 14];

        $result = (new CreateForumMetadataTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(5, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenForeignKeySetIsIncomplete(): void
    {
        $database = new ForumMetadataMigrationRecordingDatabase();
        $database->fetchValues = [11, 1, 2, 10, 13];

        $result = (new CreateForumMetadataTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class ForumMetadataMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->verificationQueries[] = $query;
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
