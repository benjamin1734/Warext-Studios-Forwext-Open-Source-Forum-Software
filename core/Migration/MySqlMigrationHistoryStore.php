<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;

final readonly class MySqlMigrationHistoryStore implements MigrationHistoryStore
{
    private const TABLE = 'forwext_migration_history';

    public function __construct(private QueryExecutor $database)
    {
    }

    public function initialize(): void
    {
        $this->database->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`migration_key` VARCHAR(255) NOT NULL,'
            . '`scope` VARCHAR(16) NOT NULL,'
            . '`owner_name` VARCHAR(191) NOT NULL,'
            . '`migration_id` VARCHAR(95) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`status` VARCHAR(16) NOT NULL,'
            . '`batch` INT UNSIGNED NOT NULL,'
            . '`attempt` INT UNSIGNED NOT NULL,'
            . '`failure_code` VARCHAR(64) NULL,'
            . '`started_at_utc` DATETIME(6) NOT NULL,'
            . '`finished_at_utc` DATETIME(6) NULL,'
            . '`duration_ms` INT UNSIGNED NULL,'
            . 'PRIMARY KEY (`migration_key`),'
            . 'KEY `idx_forwext_migration_batch` (`batch`),'
            . 'KEY `idx_forwext_migration_owner` (`scope`, `owner_name`, `migration_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function find(MigrationOwner $owner, MigrationId $id): ?MigrationRecord
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `migration_key`, `scope`, `owner_name`, `migration_id`, `checksum`, `status`, '
            . '`batch`, `attempt`, `failure_code`, `started_at_utc`, `finished_at_utc`, `duration_ms` '
            . 'FROM `' . self::TABLE . '` WHERE `migration_key` = :migration_key LIMIT 1',
            ['migration_key' => self::key($owner, $id)],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function nextBatch(): int
    {
        $value = $this->database->fetchValue(new CompiledQuery(
            'SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `' . self::TABLE . '`',
        ));

        if (!is_int($value) && !is_string($value)) {
            throw new MigrationException('Migration history returned an invalid batch value.');
        }

        $batch = (int) $value;
        if ($batch < 1) {
            throw new MigrationException('Migration batch number is invalid.');
        }

        return $batch;
    }

    public function markRunning(
        MigrationOwner $owner,
        MigrationId $id,
        string $checksum,
        int $batch,
        int $attempt,
        DateTimeImmutable $startedAt,
    ): void {
        if ($batch < 1 || $attempt < 1) {
            throw new MigrationException('Migration batch and attempt must be positive.');
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO `' . self::TABLE . '` '
            . '(`migration_key`, `scope`, `owner_name`, `migration_id`, `checksum`, `status`, `batch`, `attempt`, `failure_code`, `started_at_utc`, `finished_at_utc`, `duration_ms`) '
            . 'VALUES (:migration_key, :scope, :owner_name, :migration_id, :checksum, :status, :batch, :attempt, NULL, :started_at_utc, NULL, NULL) '
            . 'ON DUPLICATE KEY UPDATE `checksum` = VALUES(`checksum`), `status` = VALUES(`status`), `batch` = VALUES(`batch`), '
            . '`attempt` = VALUES(`attempt`), `failure_code` = NULL, `started_at_utc` = VALUES(`started_at_utc`), '
            . '`finished_at_utc` = NULL, `duration_ms` = NULL',
            [
                'migration_key' => self::key($owner, $id),
                'scope' => $owner->scope->value,
                'owner_name' => $owner->name,
                'migration_id' => $id->value(),
                'checksum' => $checksum,
                'status' => MigrationStatus::Running->value,
                'batch' => $batch,
                'attempt' => $attempt,
                'started_at_utc' => self::formatDate($startedAt),
            ],
        ));
    }

    public function markApplied(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
    ): void {
        $this->finish($owner, $id, MigrationStatus::Applied, $finishedAt, $durationMs, null);
    }

    public function markFailed(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        string $failureCode,
    ): void {
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $failureCode) !== 1) {
            throw new MigrationException('Migration failure code is invalid.');
        }

        $this->finish($owner, $id, MigrationStatus::Failed, $finishedAt, $durationMs, $failureCode);
    }

    private function finish(
        MigrationOwner $owner,
        MigrationId $id,
        MigrationStatus $status,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        ?string $failureCode,
    ): void {
        if ($durationMs < 0) {
            throw new MigrationException('Migration duration may not be negative.');
        }

        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `failure_code` = :failure_code, '
            . '`finished_at_utc` = :finished_at_utc, `duration_ms` = :duration_ms WHERE `migration_key` = :migration_key',
            [
                'status' => $status->value,
                'failure_code' => $failureCode,
                'finished_at_utc' => self::formatDate($finishedAt),
                'duration_ms' => $durationMs,
                'migration_key' => self::key($owner, $id),
            ],
        ));

        if ($affected !== 1) {
            throw new MigrationException('Migration history state transition did not affect exactly one row.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MigrationRecord
    {
        $scope = isset($row['scope']) && is_string($row['scope']) ? MigrationScope::tryFrom($row['scope']) : null;
        $ownerName = $row['owner_name'] ?? null;
        $migrationId = $row['migration_id'] ?? null;
        $checksum = $row['checksum'] ?? null;
        $status = isset($row['status']) && is_string($row['status']) ? MigrationStatus::tryFrom($row['status']) : null;

        if (
            $scope === null
            || !is_string($ownerName)
            || !is_string($migrationId)
            || !is_string($checksum)
            || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            || $status === null
        ) {
            throw new MigrationException('Migration history row is malformed.');
        }

        $owner = match ($scope) {
            MigrationScope::Core => MigrationOwner::core(),
            MigrationScope::Module => MigrationOwner::module($ownerName),
            MigrationScope::Addon => MigrationOwner::addon($ownerName),
        };

        return new MigrationRecord(
            self::requireString($row, 'migration_key'),
            $owner,
            MigrationId::fromString($migrationId),
            $checksum,
            $status,
            self::requirePositiveInt($row, 'batch'),
            self::requirePositiveInt($row, 'attempt'),
            self::parseDate(self::requireString($row, 'started_at_utc')),
            isset($row['finished_at_utc']) && is_string($row['finished_at_utc'])
                ? self::parseDate($row['finished_at_utc'])
                : null,
            isset($row['duration_ms']) ? max(0, (int) $row['duration_ms']) : null,
            isset($row['failure_code']) && is_string($row['failure_code']) ? $row['failure_code'] : null,
        );
    }

    private static function key(MigrationOwner $owner, MigrationId $id): string
    {
        return $owner->key() . ':' . $id->value();
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $date, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new MigrationException('Migration history contains an invalid UTC timestamp.');
        }

        return $parsed;
    }

    /** @param array<string, mixed> $row */
    private static function requireString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MigrationException(sprintf('Migration history field "%s" is invalid.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function requirePositiveInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if ((!is_int($value) && !is_string($value)) || (int) $value < 1) {
            throw new MigrationException(sprintf('Migration history field "%s" is invalid.', $key));
        }
        return (int) $value;
    }
}
