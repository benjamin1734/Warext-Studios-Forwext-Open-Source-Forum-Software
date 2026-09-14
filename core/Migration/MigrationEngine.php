<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Throwable;

final readonly class MigrationEngine
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private MigrationHistoryStore $history,
    ) {
    }

    /** @param iterable<Migration> $migrations */
    public function migrate(iterable $migrations): MigrationRunReport
    {
        $this->history->initialize();
        $ordered = $this->normalizeMigrations($migrations);
        $batch = $this->history->nextBatch();
        $applied = [];
        $skipped = [];
        $context = new MigrationContext($this->database);

        foreach ($ordered as $migration) {
            $key = self::key($migration);
            $checksum = MigrationFingerprint::calculate($migration);
            $existing = $this->history->find($migration->owner(), $migration->id());

            if ($existing !== null && !hash_equals($existing->checksum, $checksum)) {
                throw new MigrationIntegrityException(sprintf(
                    'Previously recorded migration "%s" no longer matches its source fingerprint.',
                    $key,
                ));
            }

            if ($existing?->status === MigrationStatus::Applied) {
                $skipped[] = $key;
                continue;
            }

            if (!$migration->isIdempotent()) {
                throw new MigrationIntegrityException(sprintf(
                    'Migration "%s" is not declared idempotent and cannot be executed by Forwext.',
                    $key,
                ));
            }

            $attempt = ($existing?->attempt ?? 0) + 1;
            $startedAt = self::now();
            $startedNs = hrtime(true);
            $this->history->markRunning(
                $migration->owner(),
                $migration->id(),
                $checksum,
                $batch,
                $attempt,
                $startedAt,
            );

            try {
                $this->runUp($migration, $context);
                $verification = $migration->verify($context);

                if (!$verification->isPassed()) {
                    throw new MigrationExecutionException(sprintf(
                        'Migration "%s" failed post-migration verification.',
                        $key,
                    ));
                }

                $finishedAt = self::now();
                $durationMs = self::durationMs($startedNs);
                $this->history->markApplied($migration->owner(), $migration->id(), $finishedAt, $durationMs);
                $applied[] = $key;
            } catch (Throwable $failure) {
                $failureCode = 'migration_failed';

                if ($migration instanceof RecoverableMigration) {
                    try {
                        $migration->recover($context, $failure);
                        $failureCode = 'migration_failed_recovered';
                    } catch (Throwable) {
                        $failureCode = 'migration_recovery_failed';
                    }
                }

                $this->history->markFailed(
                    $migration->owner(),
                    $migration->id(),
                    self::now(),
                    self::durationMs($startedNs),
                    $failureCode,
                );

                throw new MigrationExecutionException(
                    sprintf('Migration "%s" failed. See migration history/log correlation for diagnostics.', $key),
                    previous: $failure,
                );
            }
        }

        return new MigrationRunReport($batch, $applied, $skipped);
    }

    private function runUp(Migration $migration, MigrationContext $context): void
    {
        if (!$migration->isTransactional()) {
            $migration->up($context);
            return;
        }

        $this->database->transaction(static function () use ($migration, $context): void {
            $migration->up($context);
        });
    }

    /**
     * @param iterable<Migration> $migrations
     * @return list<Migration>
     */
    private function normalizeMigrations(iterable $migrations): array
    {
        $normalized = [];
        $keys = [];

        foreach ($migrations as $migration) {
            $key = self::key($migration);
            if (isset($keys[$key])) {
                throw new MigrationIntegrityException(sprintf('Duplicate migration key "%s".', $key));
            }
            $keys[$key] = true;
            $normalized[] = $migration;
        }

        usort($normalized, static function (Migration $left, Migration $right): int {
            return [
                $left->owner()->scope->value,
                $left->owner()->name,
                $left->id()->value(),
            ] <=> [
                $right->owner()->scope->value,
                $right->owner()->name,
                $right->id()->value(),
            ];
        });

        return $normalized;
    }

    private static function key(Migration $migration): string
    {
        return $migration->owner()->key() . ':' . $migration->id()->value();
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function durationMs(int $startedNs): int
    {
        return max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));
    }
}
