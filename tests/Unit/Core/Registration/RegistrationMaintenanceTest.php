<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Registration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Registration\DatabaseRegistrationMaintenance;
use PHPUnit\Framework\TestCase;

final class RegistrationMaintenanceTest extends TestCase
{
    public function testCleanupIsBoundedAndDoesNotTouchLegalAcceptanceTable(): void
    {
        $database = new MaintenanceRecordingDatabase();
        $maintenance = new DatabaseRegistrationMaintenance($database);

        $result = $maintenance->purge(
            new DateTimeImmutable('2026-09-14 22:00:00', new DateTimeZone('UTC')),
            retentionDays: 30,
            batchSize: 250,
        );

        self::assertSame([
            'verification_tokens' => 2,
            'rate_limit_buckets' => 2,
            'invites' => 2,
        ], $result);
        self::assertCount(3, $database->queries);
        foreach ($database->queries as $query) {
            self::assertStringContainsString('LIMIT 250', $query->sql);
            self::assertStringNotContainsString('forwext_user_legal_acceptances', $query->sql);
        }
    }
}

final class MaintenanceRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 2;
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
        return null;
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
