<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateContentModerationTables;
use PHPUnit\Framework\TestCase;

final class ContentModerationMigrationTest extends TestCase
{
    public function testMigrationAddsLifecycleAuditAndCompletePermissionMatrix(): void
    {
        $database = new ContentModerationMigrationRecordingDatabase();
        $migration = new CreateContentModerationTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260916000000_content_moderation', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('ADD COLUMN `deleted`', $sql);
        self::assertStringContainsString('ADD COLUMN `deleted_at_utc`', $sql);
        self::assertStringContainsString('ADD COLUMN `merged_into_thread_id`', $sql);
        self::assertStringContainsString('idx_forwext_threads_active_forum', $sql);
        self::assertStringContainsString('fk_forwext_threads_merged_into', $sql);
        self::assertStringContainsString('forwext_moderation_audit_events', $sql);

        $permissionKeys = [];
        $templateEffects = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['permission_key']) && !isset($query->parameters['template_key'])) {
                $permissionKeys[(string) $query->parameters['permission_key']] = true;
            }
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'], $query->parameters['effect'])) {
                $templateEffects[(string) $query->parameters['template_key']][(string) $query->parameters['permission_key']]
                    = (string) $query->parameters['effect'];
            }
        }

        self::assertCount(7, $permissionKeys);
        self::assertCount(5, $templateEffects);
        foreach ($templateEffects['member'] as $effect) {
            self::assertSame('deny', $effect);
        }
        foreach ($templateEffects['moderator'] as $effect) {
            self::assertSame('allow', $effect);
        }
        self::assertCount(7, $templateEffects['administrator']);
    }

    public function testVerificationRequiresLifecycleAuditIntegrityAndAllPermissionRules(): void
    {
        $database = new ContentModerationMigrationRecordingDatabase();
        $database->fetchValues = [3, 1, 1, 1, 7, 35];

        $result = (new CreateContentModerationTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(6, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenStarterRulesAreIncomplete(): void
    {
        $database = new ContentModerationMigrationRecordingDatabase();
        $database->fetchValues = [3, 1, 1, 1, 7, 34];

        $result = (new CreateContentModerationTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class ContentModerationMigrationRecordingDatabase implements TransactionalQueryExecutor
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
