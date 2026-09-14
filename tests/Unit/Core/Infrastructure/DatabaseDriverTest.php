<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Infrastructure;

use Closure;
use Forwext\Core\Cache\DatabaseCacheStore;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Lock\DatabaseLockManager;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Database\Migrations\Core\CreateInfrastructureDriverTables;
use PHPUnit\Framework\TestCase;

final class DatabaseDriverTest extends TestCase
{
    public function testInfrastructureMigrationCreatesAndVerifiesAllDriverTables(): void
    {
        $database = new RecordingDatabaseExecutor();
        $database->fetchValueResult = 4;
        $migration = new CreateInfrastructureDriverTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertCount(4, array_filter(
            $database->queries,
            static fn (CompiledQuery $query): bool => str_starts_with($query->sql, 'CREATE TABLE IF NOT EXISTS'),
        ));
    }

    public function testDatabaseCacheAndSessionUseBoundParametersAndTransactions(): void
    {
        $database = new RecordingDatabaseExecutor();
        $cache = new DatabaseCacheStore($database);
        $cache->put('cache:key', 'value', 60, ['tag:a']);
        self::assertGreaterThanOrEqual(1, $database->transactions);

        $session = new DatabaseSessionStore($database);
        $session->write('session:key', 'payload', 60);

        foreach ($database->queries as $query) {
            self::assertStringNotContainsString('cache:key', $query->sql);
            self::assertStringNotContainsString('session:key', $query->sql);
        }
    }

    public function testDatabaseLockUsesConditionalLeaseAndForUpdateVerification(): void
    {
        $database = new RecordingDatabaseExecutor();
        $database->returnInsertedLockToken = true;
        $lock = (new DatabaseLockManager($database))->acquire('migration:site');

        self::assertNotNull($lock);
        self::assertTrue($database->sawForUpdate);
        $lock->release();
    }
}

final class RecordingDatabaseExecutor implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];
    public int $transactions = 0;
    public mixed $fetchValueResult = null;
    public bool $returnInsertedLockToken = false;
    public bool $sawForUpdate = false;
    private ?string $lastLockToken = null;

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        if (str_contains($query->sql, 'INSERT INTO `forwext_locks`')) {
            $token = $query->parameters['token'] ?? null;
            $this->lastLockToken = is_string($token) ? $token : null;
        }
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        if (str_contains($query->sql, 'FOR UPDATE')) {
            $this->sawForUpdate = true;
            return $this->returnInsertedLockToken && $this->lastLockToken !== null
                ? ['token' => $this->lastLockToken]
                : ['token' => 'other-token'];
        }
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
        return $this->fetchValueResult;
    }

    public function inTransaction(): bool
    {
        return $this->transactions > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        return $callback($this);
    }
}
