<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Migration\MigrationRunReport;
use Forwext\Core\Migration\SemanticVersion;

final readonly class UpdateReport
{
    public function __construct(
        public SemanticVersion $sourceVersion,
        public SemanticVersion $targetVersion,
        public string $databaseBackupName,
        public string $databaseBackupSha256,
        public string $fileSnapshotId,
        public MigrationRunReport $migrations,
        public HealthStatus $healthStatus,
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $this->databaseBackupSha256) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->fileSnapshotId) !== 1
        ) {
            throw new UpdateException('Update report recovery metadata is invalid.');
        }
    }
}
