<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateAuthSessionIndex;
use PHPUnit\Framework\TestCase;

final class AuthSessionIndexMigrationTest extends TestCase
{
    public function testMigrationStoresOnlyHashedSessionIdentityAndUserMetadata(): void
    {
        $database = new AuthSessionIndexMigrationRecordingDatabase();
        $migration = new CreateAuthSessionIndex();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260930121500_auth_session_index', $migration->id()->value());
        self::assertCount(1, $database->executed);
        $sql = $database->executed[0]->sql;
        self::assertStringContainsString('forwext_auth_session_index', $sql);
        self::assertStringContainsString('session_hash', $sql);
        self::assertStringContainsString('user_id', $sql);
        self::assertStringContainsString('revoked_at_utc', $sql);
        self::assertStringNotContainsString('session_id', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testVerificationRejectsRawSessionIdColumn(): void
    {
        $database = new AuthSessionIndexMigrationRecordingDatabase();
        $database->fetchValues = [1, 1, 0];

        self::assertTrue((new CreateAuthSessionIndex())->verify(new MigrationContext($database))->isPassed());

        $database = new AuthSessionIndexMigrationRecordingDatabase();
        $database->fetchValues = [1, 1, 1];

        self::assertFalse((new CreateAuthSessionIndex())->verify(new MigrationContext($database))->isPassed());
    }
}

final class AuthSessionIndexMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int { $this->executed[] = $query; return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return array_shift($this->fetchValues) ?? 0; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
