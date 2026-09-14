<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Migration\InstalledVersionStore;
use Forwext\Core\Migration\InstallUpgradeEngine;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MigrationException;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\SemanticVersion;
use PHPUnit\Framework\TestCase;

final class InstallUpgradeEngineTest extends TestCase
{
    public function testInstallWritesVersionOnlyAfterSuccessfulMigrationRun(): void
    {
        $versions = new MemoryVersionStore();
        $engine = new InstallUpgradeEngine(
            new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore()),
            $versions,
        );
        $target = SemanticVersion::parse('0.1.0-alpha.1');

        $engine->install($target, [
            new ProbeMigration('20260914001000_install', MigrationOwner::core()),
        ]);

        self::assertTrue($target->equals($versions->current() ?? throw new \RuntimeException('Missing version.')));
    }

    public function testFailedInstallDoesNotWriteInstalledVersion(): void
    {
        $versions = new MemoryVersionStore();
        $engine = new InstallUpgradeEngine(
            new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore()),
            $versions,
        );

        try {
            $engine->install(SemanticVersion::parse('0.1.0'), [
                new RecoveringProbeMigration('20260914001100_fail_install', MigrationOwner::core()),
            ]);
            self::fail('Expected install to fail.');
        } catch (\Throwable) {
            self::assertNull($versions->current());
        }
    }

    public function testUpgradeRequiresExactSourceVersionAndNewerTarget(): void
    {
        $versions = new MemoryVersionStore(SemanticVersion::parse('0.1.0'));
        $engine = new InstallUpgradeEngine(
            new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore()),
            $versions,
        );

        $this->expectException(MigrationException::class);
        $engine->upgrade(
            SemanticVersion::parse('0.0.9'),
            SemanticVersion::parse('0.2.0'),
            [],
        );
    }

    public function testSuccessfulUpgradeAdvancesVersion(): void
    {
        $source = SemanticVersion::parse('0.1.0');
        $target = SemanticVersion::parse('0.2.0');
        $versions = new MemoryVersionStore($source);
        $engine = new InstallUpgradeEngine(
            new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore()),
            $versions,
        );

        $engine->upgrade($source, $target, [
            new ProbeMigration('20260914001200_upgrade', MigrationOwner::core()),
        ]);

        self::assertTrue($target->equals($versions->current() ?? throw new \RuntimeException('Missing version.')));
    }
}

final class MemoryVersionStore implements InstalledVersionStore
{
    public function __construct(private ?SemanticVersion $version = null)
    {
    }

    public function current(): ?SemanticVersion
    {
        return $this->version;
    }

    public function write(SemanticVersion $version): void
    {
        $this->version = $version;
    }
}
