<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateSocialInteractionTables;
use PHPUnit\Framework\TestCase;

final class SocialInteractionMigrationTest extends TestCase
{
    public function testMigrationCreatesReactionBookmarkFollowAndIgnoreSchema(): void
    {
        $database = new SocialInteractionMigrationRecordingDatabase();
        $migration = new CreateSocialInteractionTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260916002000_social_interactions', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        foreach (['forwext_reaction_types', 'forwext_post_reactions', 'forwext_post_bookmarks', 'forwext_user_follows', 'forwext_user_ignores'] as $table) {
            self::assertStringContainsString($table, $sql);
        }
        self::assertStringContainsString('ON DELETE CASCADE', $sql);

        $reactionSeeds = array_filter(
            $database->executedQueries,
            static fn (CompiledQuery $query): bool => isset($query->parameters['reaction_key']),
        );
        self::assertCount(6, $reactionSeeds);

        $permissionRules = array_filter(
            $database->executedQueries,
            static fn (CompiledQuery $query): bool => isset($query->parameters['template_key'], $query->parameters['permission_key']),
        );
        self::assertCount(20, $permissionRules);
    }

    public function testVerificationRequiresAllFiveTablesReactionTypesPermissionsAndRules(): void
    {
        $database = new SocialInteractionMigrationRecordingDatabase();
        $database->fetchValues = [5, 6, 4, 20];

        $result = (new CreateSocialInteractionTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(4, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenStarterRulesAreIncomplete(): void
    {
        $database = new SocialInteractionMigrationRecordingDatabase();
        $database->fetchValues = [5, 6, 4, 19];

        self::assertFalse((new CreateSocialInteractionTables())->verify(new MigrationContext($database))->isPassed());
    }
}

final class SocialInteractionMigrationRecordingDatabase implements TransactionalQueryExecutor
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
