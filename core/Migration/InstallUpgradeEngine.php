<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

final readonly class InstallUpgradeEngine
{
    public function __construct(
        private MigrationEngine $migrations,
        private InstalledVersionStore $versions,
    ) {
    }

    /** @param iterable<Migration> $migrations */
    public function install(SemanticVersion $target, iterable $migrations): MigrationRunReport
    {
        if ($this->versions->current() !== null) {
            throw new MigrationException('Forwext is already marked as installed.');
        }

        $report = $this->migrations->migrate($migrations);
        $this->versions->write($target);

        return $report;
    }

    /** @param iterable<Migration> $migrations */
    public function upgrade(
        SemanticVersion $expectedSource,
        SemanticVersion $target,
        iterable $migrations,
    ): MigrationRunReport {
        $current = $this->versions->current();
        if ($current === null) {
            throw new MigrationException('Forwext is not installed; upgrade cannot start.');
        }
        if (!$current->equals($expectedSource)) {
            throw new MigrationException(sprintf(
                'Installed version "%s" does not match required update source "%s".',
                $current->value(),
                $expectedSource->value(),
            ));
        }
        if (!$target->isGreaterThan($expectedSource)) {
            throw new MigrationException('Upgrade target version must be newer than the source version.');
        }

        $report = $this->migrations->migrate($migrations);
        $this->versions->write($target);

        return $report;
    }
}
