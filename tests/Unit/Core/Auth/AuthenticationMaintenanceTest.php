<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\DatabaseAuthenticationMaintenance;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use PHPUnit\Framework\TestCase;

final class AuthenticationMaintenanceTest extends TestCase
{
    public function testMaintenanceUsesBoundedDeletesAndDoesNotDeleteCredentialsOrDevices(): void
    {
        $database = new AuthenticationMaintenanceDatabase();
        $maintenance = new DatabaseAuthenticationMaintenance($database);

        $result = $maintenance->purge(
            new DateTimeImmutable('2026-09-14 23:59:00', new DateTimeZone('UTC')),
            historyRetentionDays: 180,
            tokenRetentionDays: 30,
            batchSize: 250,
        );

        self::assertSame([
            'login_history' => 2,
            'rate_limits' => 2,
            'remember_tokens' => 2,
            'challenge_tokens' => 2,
        ], $result);
        self::assertCount(4, $database->queries);
        foreach ($database->queries as $query) {
            self::assertStringContainsString('LIMIT 250', $query->sql);
            self::assertStringNotContainsString('forwext_user_credentials', $query->sql);
            self::assertStringNotContainsString('forwext_user_devices', $query->sql);
        }
    }
}

final class AuthenticationMaintenanceDatabase implements TransactionalQueryExecutor
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
