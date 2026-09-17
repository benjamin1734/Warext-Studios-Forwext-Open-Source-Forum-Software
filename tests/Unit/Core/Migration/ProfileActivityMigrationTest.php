<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateProfileActivityTables;
use PHPUnit\Framework\TestCase;

final class ProfileActivityMigrationTest extends TestCase
{
    public function testMigrationCreatesProfilePostsCommentsReactionsAndPrivacySettings(): void
    {
        $database = new ProfileActivityMigrationRecordingDatabase();
        $migration = new CreateProfileActivityTables();
        $migration->up(new MigrationContext($database));

        self::assertSame('20260916003000_profile_activity', $migration->id()->value());
        $sql = implode("\n", array_map(static fn (CompiledQuery $query): string => $query->sql, $database->executedQueries));
        foreach (['forwext_profile_activity_settings','forwext_profile_posts','forwext_profile_comments','forwext_profile_post_reactions'] as $table) {
            self::assertStringContainsString($table, $sql);
        }
        self::assertStringContainsString('ON DELETE SET NULL', $sql);
        self::assertStringContainsString('forwext_reaction_types', $sql);

        $rules = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'], $query->parameters['effect'])) {
                $rules[(string) $query->parameters['template_key']][(string) $query->parameters['permission_key']] = (string) $query->parameters['effect'];
            }
        }
        self::assertCount(5, $rules);
        self::assertSame('deny', $rules['member']['profile.post.moderate']);
        self::assertSame('allow', $rules['member']['profile.post.create']);
        self::assertSame('allow', $rules['moderator']['profile.post.moderate']);
        self::assertSame('allow', $rules['administrator']['profile.post.react']);
    }

    public function testVerificationRequiresSchemaPermissionsAndAllStarterRules(): void
    {
        $database = new ProfileActivityMigrationRecordingDatabase();
        $database->fetchValues = [4, 8, 5, 25];
        self::assertTrue((new CreateProfileActivityTables())->verify(new MigrationContext($database))->isPassed());
        self::assertCount(4, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenForeignKeysAreIncomplete(): void
    {
        $database = new ProfileActivityMigrationRecordingDatabase();
        $database->fetchValues = [4, 7, 5, 25];
        self::assertFalse((new CreateProfileActivityTables())->verify(new MigrationContext($database))->isPassed());
    }
}

final class ProfileActivityMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $executedQueries = [];
    /** @var list<CompiledQuery> */ public array $verificationQueries = [];
    /** @var list<int> */ public array $fetchValues = [];
    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { $this->verificationQueries[] = $query; return array_shift($this->fetchValues) ?? 0; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
