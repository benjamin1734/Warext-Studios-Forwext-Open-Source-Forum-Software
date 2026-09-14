<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Database;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\DatabaseException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseConnectionTest extends TestCase
{
    private function connection(): DatabaseConnection
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated database connection tests.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        return new DatabaseConnection($pdo);
    }

    public function testPreparedExecutionAndFetch(): void
    {
        $connection = $this->connection();
        self::assertSame(1, $connection->execute(new CompiledQuery(
            'INSERT INTO items (name) VALUES (:name)',
            ['name' => 'safe value'],
        )));

        $row = $connection->fetchOne(new CompiledQuery(
            'SELECT id, name FROM items WHERE name = :name',
            ['name' => 'safe value'],
        ));

        self::assertNotNull($row);
        self::assertSame('safe value', $row['name']);
    }

    public function testNestedTransactionUsesSavepointAndRollsBackOnlyInnerWork(): void
    {
        $connection = $this->connection();

        $connection->transaction(function (DatabaseConnection $database): void {
            $database->execute(new CompiledQuery('INSERT INTO items (name) VALUES (:name)', ['name' => 'outer-a']));

            try {
                $database->transaction(function (DatabaseConnection $nested): void {
                    $nested->execute(new CompiledQuery('INSERT INTO items (name) VALUES (:name)', ['name' => 'inner']));
                    throw new RuntimeException('rollback inner');
                });
            } catch (RuntimeException) {
                // Expected: outer transaction remains usable after inner savepoint rollback.
            }

            $database->execute(new CompiledQuery('INSERT INTO items (name) VALUES (:name)', ['name' => 'outer-b']));
        });

        $rows = $connection->fetchAll(new CompiledQuery('SELECT name FROM items ORDER BY id'));
        self::assertSame([['name' => 'outer-a'], ['name' => 'outer-b']], $rows);
    }

    public function testLockMarkedQueryRequiresTransactionBeforePreparation(): void
    {
        $connection = $this->connection();

        $this->expectException(DatabaseException::class);
        $connection->fetchAll(new CompiledQuery('SELECT 1', requiresTransaction: true));
    }

    public function testLockMarkedQueryCanExecuteInsideTransaction(): void
    {
        $connection = $this->connection();

        $value = $connection->transaction(
            static fn (DatabaseConnection $database): mixed =>
                $database->fetchValue(new CompiledQuery('SELECT 1', requiresTransaction: true)),
        );

        self::assertSame(1, (int) $value);
    }
}
