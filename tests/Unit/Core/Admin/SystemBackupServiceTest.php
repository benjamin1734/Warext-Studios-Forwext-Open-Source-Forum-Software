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

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'SET TRANSACTION')) {
            return 0;
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
