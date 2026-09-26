<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Admin\Operations\SystemBackupService;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SystemBackupServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-backup-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            @unlink($this->directory . '/' . $name);
        }
        @rmdir($this->directory);
    }

    public function testLogicalBackupPreservesSchemaAndBinaryRowsAndVerifiesManifest(): void
    {
        $database = new SystemBackupFakeDatabase();
        $service = new SystemBackupService($database, $this->directory, 50);
        $at = new DateTimeImmutable('2026-09-25 08:15:00.000000', new DateTimeZone('UTC'));

        $created = $service->create($at);
        $verified = $service->verify($created->name);

        self::assertTrue($verified->verified);
        self::assertSame(1, $verified->tableCount);
        self::assertSame(2, $verified->rowCount);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $verified->sha256);

        $payload = (string) file_get_contents($this->directory . '/' . $created->name);
        self::assertStringContainsString('CREATE TABLE forwext_alpha', $payload);
        self::assertStringContainsString(base64_encode("\x00\xFFbinary"), $payload);
        self::assertStringContainsString('"type":"manifest","tables":1,"rows":2', $payload);
    }

    public function testVerifiedBackupCanRestoreSchemaAndBinaryRows(): void
    {
        $database = new SystemBackupFakeDatabase();
        $service = new SystemBackupService($database, $this->directory, 50);
        $created = $service->create(new DateTimeImmutable('2026-09-25 08:15:00', new DateTimeZone('UTC')));

        $restored = $service->restore(
            $created->name,
            $created->sha256 ?? throw new RuntimeException('Missing backup checksum.'),
        );

        self::assertTrue($restored->verified);
        self::assertContains('SET FOREIGN_KEY_CHECKS=0', $database->executedSql);
        self::assertContains('DROP TABLE IF EXISTS `forwext_alpha`', $database->executedSql);
        self::assertContains('CREATE TABLE forwext_alpha (id INT PRIMARY KEY, payload BLOB)', $database->executedSql);
        self::assertContains('SET FOREIGN_KEY_CHECKS=1', $database->executedSql);
        self::assertCount(2, $database->restoredRows);
        self::assertSame("\x00\xFFbinary", $database->restoredRows[0]['v1'] ?? null);
        self::assertSame('plain', $database->restoredRows[1]['v1'] ?? null);
    }

    public function testRestoreRejectsWrongChecksumBeforeDestructiveSql(): void
    {
        $database = new SystemBackupFakeDatabase();
        $service = new SystemBackupService($database, $this->directory, 50);
        $created = $service->create(new DateTimeImmutable('2026-09-25 08:15:00', new DateTimeZone('UTC')));
        $database->executedSql = [];

        try {
            $service->restore($created->name, str_repeat('0', 64));
            self::fail('Expected restore checksum mismatch.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('checksum', strtolower($exception->getMessage()));
        }

        self::assertSame([], $database->executedSql);
        self::assertSame([], $database->restoredRows);
    }

    public function testDeleteAcceptsOnlyServiceGeneratedBackupNames(): void
    {
        $service = new SystemBackupService(new SystemBackupFakeDatabase(), $this->directory, 50);

        $this->expectException(RuntimeException::class);
        $service->delete('../config/secret.key');
    }
}

/** @internal */
final class SystemBackupFakeDatabase implements TransactionalQueryExecutor
{
    private bool $transaction = false;

    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<array<string,string|int|float|bool|null>> */
    public array $restoredRows = [];

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'SET TRANSACTION')) {
            return 0;
        }
        if (
            $query->sql === 'SET FOREIGN_KEY_CHECKS=0'
            || $query->sql === 'SET FOREIGN_KEY_CHECKS=1'
            || $query->sql === 'DROP TABLE IF EXISTS `forwext_alpha`'
            || $query->sql === 'CREATE TABLE forwext_alpha (id INT PRIMARY KEY, payload BLOB)'
        ) {
            $this->executedSql[] = $query->sql;
            return 0;
        }
        if ($query->sql === 'INSERT INTO `forwext_alpha` (`id`,`payload`) VALUES (:v0,:v1)') {
            $this->executedSql[] = $query->sql;
            $this->restoredRows[] = $query->parameters;
            return 1;
        }
        throw new RuntimeException('Unexpected execute SQL: ' . $query->sql);
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        if ($query->sql === 'SHOW CREATE TABLE forwext_alpha') {
            return [
                'Table'=>'forwext_alpha',
                'Create Table'=>'CREATE TABLE forwext_alpha (id INT PRIMARY KEY, payload BLOB)',
            ];
        }
        throw new RuntimeException('Unexpected fetchOne SQL: ' . $query->sql);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        if (str_contains($query->sql, 'information_schema.TABLES')) {
            return [['TABLE_NAME'=>'forwext_alpha']];
        }
        if ($query->sql === 'SELECT * FROM forwext_alpha LIMIT 50 OFFSET 0') {
            return [
                ['id'=>1,'payload'=>"\x00\xFFbinary"],
                ['id'=>2,'payload'=>'plain'],
            ];
        }
        if ($query->sql === 'SELECT * FROM forwext_alpha LIMIT 50 OFFSET 50') {
            return [];
        }
        throw new RuntimeException('Unexpected fetchAll SQL: ' . $query->sql);
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        throw new RuntimeException('Unexpected fetchValue SQL: ' . $query->sql);
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transaction = true;
        try {
            return $callback($this);
        } finally {
            $this->transaction = false;
        }
    }
}
