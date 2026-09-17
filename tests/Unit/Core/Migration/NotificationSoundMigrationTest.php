<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateNotificationSoundTables;
use PHPUnit\Framework\TestCase;

final class NotificationSoundMigrationTest extends TestCase
{
    public function testMigrationCreatesGlobalAndCategorySoundTables(): void
    {
        $database = new NotificationSoundMigrationDatabase();
        $migration = new CreateNotificationSoundTables();
        $migration->up(new MigrationContext($database));
        self::assertSame('20260917002000_notification_sound', $migration->id()->value());
        $sql = implode("\n", array_map(static fn (CompiledQuery $query): string => $query->sql, $database->executed));
        self::assertStringContainsString('forwext_notification_sound_settings', $sql);
        self::assertStringContainsString('forwext_notification_sound_categories', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testVerificationRequiresTablesForeignKeysAndNotificationPermissions(): void
    {
        $database = new NotificationSoundMigrationDatabase();
        $database->values = [2, 2, 2];
        self::assertTrue((new CreateNotificationSoundTables())->verify(new MigrationContext($database))->isPassed());
        self::assertCount(3, $database->verification);
    }

    public function testVerificationFailsClosedWhenPermissionDependencyIsMissing(): void
    {
        $database = new NotificationSoundMigrationDatabase();
        $database->values = [2, 2, 1];
        self::assertFalse((new CreateNotificationSoundTables())->verify(new MigrationContext($database))->isPassed());
    }
}

final class NotificationSoundMigrationDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $executed = [];
    /** @var list<CompiledQuery> */ public array $verification = [];
    /** @var list<int> */ public array $values = [];
    public function execute(CompiledQuery $query): int { $this->executed[] = $query; return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { $this->verification[] = $query; return array_shift($this->values) ?? 0; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
