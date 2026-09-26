<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Update;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Admin\Operations\SystemBackupService;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Health\HealthCheck;
use Forwext\Core\Health\HealthCheckResult;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Lock\LockHandle;
use Forwext\Core\Lock\LockManager;
use Forwext\Core\Migration\FileInstalledVersionStore;
use Forwext\Core\Migration\InstallUpgradeEngine;
use Forwext\Core\Migration\MigrationHistoryStore;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationRecord;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Update\UpdateCoordinator;
use Forwext\Core\Update\UpdateException;
use Forwext\Core\Update\UpdateFileTransaction;
use Forwext\Core\Update\UpdateMaintenanceLock;
use Forwext\Core\Update\UpdatePackageInspector;
use Forwext\Core\Update\UpdateRebuildRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class UpdateCoordinatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension is unavailable.');
        }

        $this->directory = sys_get_temp_dir() . '/forwext-update-coordinator-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/project', 0700, true);
        mkdir($this->directory . '/backups', 0700, true);
        mkdir($this->directory . '/snapshots', 0700, true);
        file_put_contents($this->directory . '/project/VERSION', "0.0.7.63-dev\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            self::removeDirectory($this->directory);
        }
    }

    public function testSuccessfulUpdateCommitsFilesVersionAndReleasesMaintenance(): void
    {
        $released = false;
        $coordinator = $this->coordinator(
            new UpdateRebuildRegistry(['cache.clear'=>static function (): void {}]),
            $released,
            HealthStatus::Healthy,
        );

        $report = $coordinator->apply(
            $this->package(['cache.clear']),
            new DateTimeImmutable('2026-09-26 18:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame('0.0.7.63-dev', $report->sourceVersion->value());
        self::assertSame('0.0.7.64-dev', $report->targetVersion->value());
        self::assertSame(HealthStatus::Healthy, $report->healthStatus);
        self::assertSame("0.0.7.64-dev\n", file_get_contents($this->directory . '/project/VERSION'));
        self::assertSame(
            '0.0.7.64-dev',
            (new FileInstalledVersionStore($this->directory . '/installed-version.json'))->current()?->value(),
        );
        self::assertFalse(is_file($this->directory . '/maintenance.json'));
        self::assertTrue($released);
    }

    public function testRebuildFailureRestoresDatabaseVersionAndFilesBeforeLeavingMaintenance(): void
    {
        $released = false;
        $coordinator = $this->coordinator(
            new UpdateRebuildRegistry([
                'cache.clear'=>static function (): void {
                    throw new RuntimeException('synthetic rebuild failure');
                },
            ]),
            $released,
            HealthStatus::Healthy,
        );

        try {
            $coordinator->apply(
                $this->package(['cache.clear']),
                new DateTimeImmutable('2026-09-26 18:05:00', new DateTimeZone('UTC')),
            );
            self::fail('Expected update rollback failure report.');
        } catch (UpdateException $exception) {
            self::assertStringContainsString(
                'database, installed version and application files were restored',
                $exception->getMessage(),
            );
        }

        self::assertSame("0.0.7.63-dev\n", file_get_contents($this->directory . '/project/VERSION'));
        self::assertSame(
            '0.0.7.63-dev',
            (new FileInstalledVersionStore($this->directory . '/installed-version.json'))->current()?->value(),
        );
        self::assertFalse(is_file($this->directory . '/maintenance.json'));
        self::assertTrue($released);

        $backups = glob($this->directory . '/backups/forwext-backup-*.jsonl');
        self::assertIsArray($backups);
        self::assertCount(1, $backups);
    }

    public function testUnhealthyPostUpdateVerificationTriggersFullRollback(): void
    {
        $released = false;
        $coordinator = $this->coordinator(
            new UpdateRebuildRegistry(['cache.clear'=>static function (): void {}]),
            $released,
            HealthStatus::Unhealthy,
        );

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('database, installed version and application files were restored');

        try {
            $coordinator->apply(
                $this->package(['cache.clear']),
                new DateTimeImmutable('2026-09-26 18:10:00', new DateTimeZone('UTC')),
            );
        } finally {
            self::assertSame("0.0.7.63-dev\n", file_get_contents($this->directory . '/project/VERSION'));
            self::assertSame(
                '0.0.7.63-dev',
                (new FileInstalledVersionStore($this->directory . '/installed-version.json'))->current()?->value(),
            );
            self::assertFalse(is_file($this->directory . '/maintenance.json'));
            self::assertTrue($released);
        }
    }

    private function coordinator(
        UpdateRebuildRegistry $rebuilds,
        bool &$released,
        HealthStatus $healthStatus,
    ): UpdateCoordinator {
        $database = new class implements TransactionalQueryExecutor {
            public function execute(CompiledQuery $query): int
            {
                return 0;
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
        };

        $history = new class implements MigrationHistoryStore {
            public function initialize(): void
            {
            }

            public function find(MigrationOwner $owner, MigrationId $id): ?MigrationRecord
            {
                return null;
            }

            public function nextBatch(): int
            {
                return 1;
            }

            public function markRunning(
                MigrationOwner $owner,
                MigrationId $id,
                string $checksum,
                int $batch,
                int $attempt,
                DateTimeImmutable $startedAt,
            ): void {
            }

            public function markApplied(
                MigrationOwner $owner,
                MigrationId $id,
                DateTimeImmutable $finishedAt,
                int $durationMs,
            ): void {
            }

            public function markFailed(
                MigrationOwner $owner,
                MigrationId $id,
                DateTimeImmutable $finishedAt,
                int $durationMs,
                string $failureCode,
            ): void {
            }
        };

        $versions = new FileInstalledVersionStore($this->directory . '/installed-version.json');
        $versions->write(\Forwext\Core\Migration\SemanticVersion::parse('0.0.7.63-dev'));

        $health = new HealthService([
            new class($healthStatus) implements HealthCheck {
                public function __construct(private HealthStatus $status)
                {
                }

                public function name(): string
                {
                    return 'update-test';
                }

                public function check(): HealthCheckResult
                {
                    return new HealthCheckResult('update-test', $this->status);
                }
            },
        ]);

        return new UpdateCoordinator(
            new UpdatePackageInspector(),
            $versions,
            self::lockManager($released),
            new UpdateMaintenanceLock($this->directory . '/maintenance.json'),
            new SystemBackupService($database, $this->directory . '/backups', 50),
            new UpdateFileTransaction($this->directory . '/project', $this->directory . '/snapshots'),
            new InstallUpgradeEngine(new MigrationEngine($database, $history), $versions),
            $rebuilds,
            $health,
            static fn (): iterable => [],
        );
    }

    private static function lockManager(bool &$released): LockManager
    {
        return new class($released) implements LockManager {
            public function __construct(private bool &$released)
            {
            }

            public function acquire(string $name, int $ttlSeconds = 30, int $waitMilliseconds = 0): ?LockHandle
            {
                return new class($name, $this->released) implements LockHandle {
                    public function __construct(
                        private string $lockName,
                        private bool &$released,
                    ) {
                    }

                    public function name(): string
                    {
                        return $this->lockName;
                    }

                    public function release(): void
                    {
                        $this->released = true;
                    }
                };
            }
        };
    }

    /** @param list<string> $rebuild */
    private function package(array $rebuild): string
    {
        $path = $this->directory . '/update-' . bin2hex(random_bytes(4)) . '.zip';
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $version = "0.0.7.64-dev\n";
        $archive->addFromString('VERSION', $version);
        $archive->addFromString('update-manifest.json', json_encode([
            'schema'=>1,
            'source_version'=>'0.0.7.63-dev',
            'target_version'=>'0.0.7.64-dev',
            'add'=>[],
            'replace'=>['VERSION'],
            'delete'=>[],
            'preserve'=>[
                'config/generated.php',
                'config/secret.key',
                'public/storage/**',
                'storage/backups/**',
                'storage/files/**',
                'storage/install/installed-version.json',
                'storage/logs/**',
                'storage/secrets/**',
            ],
            'migrations'=>[],
            'rebuild'=>$rebuild,
            'checksum_algorithm'=>'sha256',
            'checksum'=>['VERSION'=>hash('sha256', $version)],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $archive->close();

        return $path;
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
