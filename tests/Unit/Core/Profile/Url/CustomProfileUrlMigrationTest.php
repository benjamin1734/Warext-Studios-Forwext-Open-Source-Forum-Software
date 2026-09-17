<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Url;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateCustomProfileUrlTables;
use Forwext\Database\Migrations\Core\CreateUserDomainTables;
use PHPUnit\Framework\TestCase;

final class CustomProfileUrlMigrationTest extends TestCase
{
    public function testMigrationCreatesPermanentClaimsAndUniqueCurrentSlugTables(): void
    {
        $database = new ProfileUrlMigrationExecutor();
        $migration = new CreateCustomProfileUrlTables();

        $migration->up(new MigrationContext($database));

        self::assertCount(2, $database->executedSql);
        $sql = implode("\n", $database->executedSql);
        self::assertStringContainsString('`forwext_profile_url_claims`', $sql);
        self::assertStringContainsString('`forwext_user_profile_urls`', $sql);
        self::assertStringContainsString('COLLATE ascii_bin', $sql);
        self::assertStringContainsString('PRIMARY KEY (`slug_key`)', $sql);
        self::assertStringContainsString('UNIQUE KEY `uq_profile_url_current_slug` (`slug_key`)', $sql);
        self::assertStringContainsString('ON DELETE SET NULL', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
        self::assertSame(2, substr_count($sql, 'REFERENCES `forwext_users` (`user_id`)'));
        self::assertStringNotContainsString('REFERENCES `forwext_users` (`id`)', $sql);
        self::assertFalse($migration->isTransactional());
        self::assertTrue($migration->isIdempotent());
    }

    public function testForeignKeysMatchCanonicalUserDomainPrimaryKey(): void
    {
        $userDatabase = new ProfileUrlMigrationExecutor();
        (new CreateUserDomainTables())->up(new MigrationContext($userDatabase));
        $userSql = implode("\n", $userDatabase->executedSql);
        self::assertStringContainsString('PRIMARY KEY (`user_id`)', $userSql);

        $profileDatabase = new ProfileUrlMigrationExecutor();
        (new CreateCustomProfileUrlTables())->up(new MigrationContext($profileDatabase));
        $profileSql = implode("\n", $profileDatabase->executedSql);

        self::assertSame(2, substr_count($profileSql, 'REFERENCES `forwext_users` (`user_id`)'));
        self::assertStringNotContainsString('REFERENCES `forwext_users` (`id`)', $profileSql);
    }

    public function testMigrationVerificationRequiresBothTables(): void
    {
        $database = new ProfileUrlMigrationExecutor();
        $migration = new CreateCustomProfileUrlTables();
        $context = new MigrationContext($database);

        $database->tableCount = 2;
        self::assertTrue($migration->verify($context)->isPassed());

        $database->tableCount = 1;
        $verification = $migration->verify($context);
        self::assertFalse($verification->isPassed());
        self::assertSame(['Custom profile URL tables are missing.'], $verification->failures());
    }
}

final class ProfileUrlMigrationExecutor implements TransactionalQueryExecutor
{
    /** @var list<string> */
    public array $executedSql = [];

    public int $tableCount = 2;

    public function execute(CompiledQuery $query): int
    {
        $this->executedSql[] = $query->sql;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        unset($query);
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        self::assertVerificationQuery($query->sql);
        return $this->tableCount;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }

    private static function assertVerificationQuery(string $sql): void
    {
        if (!str_contains($sql, 'information_schema') || !str_contains($sql, 'forwext_user_profile_urls')) {
            throw new \RuntimeException('Unexpected migration verification query.');
        }
    }
}
