<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SystemBackupEntry
{
    public function __construct(
        public string $name,
        public int $sizeBytes,
        public DateTimeImmutable $modifiedAt,
        public ?string $sha256 = null,
        public ?bool $verified = null,
        public ?int $tableCount = null,
        public ?int $rowCount = null,
    ) {
        if (preg_match('/^forwext-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.jsonl$/D', $this->name) !== 1) {
            throw new InvalidArgumentException('Backup filename is invalid.');
        }
        if ($this->sizeBytes < 0) {
            throw new InvalidArgumentException('Backup size is invalid.');
        }
        if ($this->sha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Backup checksum is invalid.');
        }
        if ($this->tableCount !== null && $this->tableCount < 0) {
            throw new InvalidArgumentException('Backup table count is invalid.');
        }
        if ($this->rowCount !== null && $this->rowCount < 0) {
            throw new InvalidArgumentException('Backup row count is invalid.');
        }
    }
}
