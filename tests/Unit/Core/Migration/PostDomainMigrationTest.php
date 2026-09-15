<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreatePostDomainTables;
use PHPUnit\Framework\TestCase;

final class PostDomainMigrationTest extends TestCase
{
    public function testMigrationCreatesPostHistoryPermissionsAndStarterRules(): void
    {
        $database = new PostDomainMigrationRecordingDatabase();
        $migration = new CreatePostDomainTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235955_post_domain', $migration->id()->value());
        self::assertCount(38, $database->executedQueries);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_posts', $sql);
        self::assertStringContainsString('forwext_post_history', $sql);
        self::assertStringContainsString('uq_forwext_posts_thread_position', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
        self::assertStringContainsString('ON DELETE SET NULL', $sql);

        $templateEffects = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'])) {
                $templateEffects[
                    (string) $query->parameters['template_key'] . ':' . (string) $query->parameters['permission_key']
                ] = (string) $query->parameters['effect'];
            }
        }
        self::assertCount(30, $templateEffects);
        self::assertSame('allow', $templateEffects['member:forum.post.edit_own']);
        self::assertSame('allow', $templateEffects['verified:forum.post.delete_own']);
        self::assertSame('deny', $templateEffects['member:forum.post.edit_any']);
        self::assertSame('deny', $templateEffects['new_user:forum.post.restore']);
        self::assertSame('allow', $templateEffects['moderator:forum.post.moderate']);
        self::assertSame('allow', $templateEffects['administrator:forum.post.delete_any']);
    }

    public function testVerificationRequiresSchemaPositionPermissionsTemplatesAndForeignKeys(): void
    {
        $database = new PostDomainMigrationRecordingDatabase();
        $database->fetchValues = [2, 2, 6, 30, 4];

        $result = (new CreatePostDomainTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(5, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenPositionUniqueIndexIsIncomplete(): void
    {
        $database = new PostDomainMigrationRecordingDatabase();
        $database->fetchValues = [2, 1, 6, 30, 4];

        $result = (new CreatePostDomainTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class PostDomainMigrationRecordingDatabase implements TransactionalQueryExecutor
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
