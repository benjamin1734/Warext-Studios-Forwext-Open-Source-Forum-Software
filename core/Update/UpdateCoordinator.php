<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Admin\Operations\SystemBackupService;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Lock\LockManager;
use Forwext\Core\Migration\InstalledVersionStore;
use Forwext\Core\Migration\InstallUpgradeEngine;
use Forwext\Core\Migration\Migration;
use Throwable;

final readonly class UpdateCoordinator
{
    /** @var Closure():iterable<Migration> */
    private Closure $migrationProvider;

    /** @param Closure():iterable<Migration> $migrationProvider */
    public function __construct(
        private UpdatePackageInspector $packages,
        private InstalledVersionStore $versions,
        private LockManager $locks,
        private UpdateMaintenanceLock $maintenance,
        private SystemBackupService $backups,
        private UpdateFileTransaction $files,
        private InstallUpgradeEngine $upgrades,
        private UpdateRebuildRegistry $rebuilds,
        private HealthService $health,
        Closure $migrationProvider,
    ) {
        $this->migrationProvider = $migrationProvider;
    }

    public function apply(string $packagePath, DateTimeImmutable $at): UpdateReport
    {
        $package = $this->packages->inspect($packagePath);
        $source = $package->manifest->sourceVersion;
        $target = $package->manifest->targetVersion;

        $this->assertInstalledVersion($source->value());
        $this->rebuilds->validate($package->manifest->rebuild);

        $lock = $this->locks->acquire('forwext.update', 3600, 0);
        if ($lock === null) {
            throw new UpdateException('Another Forwext update process is already active.');
        }

        try {
            $this->assertInstalledVersion($source->value());

            $backup = $this->backups->create($at);
            $verifiedBackup = $this->backups->verify($backup->name);
            if (
                !$verifiedBackup->verified
                || $verifiedBackup->sha256 === null
                || preg_match('/^[a-f0-9]{64}$/D', $verifiedBackup->sha256) !== 1
            ) {
                throw new UpdateException('Pre-update database backup verification failed.');
            }

            $lease = $this->maintenance->enter($source, $target, $at);
            try {
                $snapshot = $this->files->apply($package);
            } catch (Throwable $failure) {
                $this->leaveMaintenanceOrFail($lease, 'Update file preparation failed and maintenance mode could not be released.');
                throw new UpdateException('Update file preparation failed before migrations started.', previous: $failure);
            }

            try {
                $migrationProvider = $this->migrationProvider;
                $migrationReport = $this->upgrades->upgrade(
                    $source,
                    $target,
                    $migrationProvider(),
                );

                $this->rebuilds->run($package->manifest->rebuild);
                $health = $this->health->report();
                if ($health->status === HealthStatus::Unhealthy) {
                    throw new UpdateException('Post-update health verification reported an unhealthy installation.');
                }
            } catch (Throwable $failure) {
                $recoveryFailures = [];

                try {
                    $this->backups->restore($verifiedBackup->name, $verifiedBackup->sha256);
                } catch (Throwable $recoveryFailure) {
                    $recoveryFailures[] = $recoveryFailure;
                }

                try {
                    $this->versions->write($source);
                } catch (Throwable $recoveryFailure) {
                    $recoveryFailures[] = $recoveryFailure;
                }

                try {
                    $this->files->rollback($snapshot);
                } catch (Throwable $recoveryFailure) {
                    $recoveryFailures[] = $recoveryFailure;
                }

                if ($recoveryFailures !== []) {
                    throw new UpdateException(
                        'Update failed and automatic recovery was incomplete. Maintenance mode remains active for manual recovery.',
                        previous: $recoveryFailures[0],
                    );
                }

                $this->leaveMaintenanceOrFail(
                    $lease,
                    'Update rollback completed but maintenance mode could not be released.',
                );

                throw new UpdateException(
                    'Update failed; database, installed version and application files were restored.',
                    previous: $failure,
                );
            }

            try {
                $this->maintenance->leave($lease);
            } catch (Throwable $failure) {
                throw new UpdateException(
                    'Update completed successfully but maintenance mode could not be released automatically.',
                    previous: $failure,
                );
            }

            return new UpdateReport(
                $source,
                $target,
                $verifiedBackup->name,
                $verifiedBackup->sha256,
                $snapshot->id,
                $migrationReport,
                $health->status,
            );
        } finally {
            $lock->release();
        }
    }

    private function assertInstalledVersion(string $expected): void
    {
        $current = $this->versions->current();
        if ($current === null) {
            throw new UpdateException('Forwext is not installed; an update package cannot be applied.');
        }
        if ($current->value() !== $expected) {
            throw new UpdateException(sprintf(
                'Installed version "%s" does not match update source "%s".',
                $current->value(),
                $expected,
            ));
        }
    }

    private function leaveMaintenanceOrFail(UpdateMaintenanceLease $lease, string $message): void
    {
        try {
            $this->maintenance->leave($lease);
        } catch (Throwable $failure) {
            throw new UpdateException($message, previous: $failure);
        }
    }
}
