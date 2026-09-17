<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateNotificationAlertTables;
use PHPUnit\Framework\TestCase;

final class NotificationAlertMigrationTest extends TestCase
{
    public function testMigrationCreatesAlertPreferenceDedupeAndDeliverySchema(): void
    {
        $database = new NotificationMigrationRecordingDatabase();
        $migration = new CreateNotificationAlertTables();
        $migration->up(new MigrationContext($database));

        self::assertSame('20260917001000_notification_alerts', $migration->id()->value());
        $sql = implode("\n", array_map(static fn (CompiledQuery $query): string => $query->sql, $database->executedQueries));
        foreach (['forwext_notifications','forwext_notification_dedupes','forwext_notification_preferences','forwext_notification_deliveries'] as $table) {
            self::assertStringContainsString($table, $sql);
        }
        self::assertStringContainsString('notification.alert.view', $this->parameters($database, 'permission_key'));
        self::assertStringContainsString('notification.preference.manage', $this->parameters($database, 'permission_key'));
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testVerificationRequiresAllTablesForeignKeysPermissionsAndStarterRules(): void
    {
        $database = new NotificationMigrationRecordingDatabase();
        $database->fetchValues = [4, 5, 2, 10];
        self::assertTrue((new CreateNotificationAlertTables())->verify(new MigrationContext($database))->isPassed());
        self::assertCount(4, $database->verificationQueries);
    }

    public function testVerificationFailsClosedOnMissingStarterRule(): void
    {
        $database = new NotificationMigrationRecordingDatabase();
        $database->fetchValues = [4, 5, 2, 9];
        self::assertFalse((new CreateNotificationAlertTables())->verify(new MigrationContext($database))->isPassed());
    }

    private function parameters(NotificationMigrationRecordingDatabase $database, string $key): string
    {
        $values = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters[$key])) $values[] = (string) $query->parameters[$key];
        }
        return implode('|', $values);
    }
}

final class NotificationMigrationRecordingDatabase implements TransactionalQueryExecutor
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
