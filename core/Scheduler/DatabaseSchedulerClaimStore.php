<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;

final readonly class DatabaseSchedulerClaimStore implements SchedulerClaimStore
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function claim(string $taskName, DateTimeImmutable $minuteBucket): ?SchedulerClaim
    {
        $claim = new SchedulerClaim(
            $taskName,
            self::minute($minuteBucket),
            bin2hex(random_bytes(16)),
        );

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO `forwext_scheduler_claims` '
            . '(`task_name`, `minute_bucket_utc`, `claim_token`, `claimed_at_utc`) '
            . 'VALUES (:task_name, :minute_bucket_utc, :claim_token, :claimed_at_utc)',
            [
                'task_name' => $claim->taskName,
                'minute_bucket_utc' => self::formatDate($claim->minuteBucket),
                'claim_token' => $claim->token,
                'claimed_at_utc' => self::formatDate(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            ],
        ));

        if ($affected === 0) {
            return null;
        }
        if ($affected !== 1) {
            throw new \RuntimeException('Scheduler claim insertion affected an unexpected number of rows.');
        }

        return $claim;
    }

    public function release(SchedulerClaim $claim): void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_scheduler_claims` '
            . 'WHERE `task_name` = :task_name AND `minute_bucket_utc` = :minute_bucket_utc '
            . 'AND `claim_token` = :claim_token',
            [
                'task_name' => $claim->taskName,
                'minute_bucket_utc' => self::formatDate($claim->minuteBucket),
                'claim_token' => $claim->token,
            ],
        ));
    }

    public function pruneBefore(DateTimeImmutable $cutoff, int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Scheduler prune limit must be between 1 and 10000.');
        }

        return $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_scheduler_claims` '
            . 'WHERE `minute_bucket_utc` < :cutoff_utc ORDER BY `minute_bucket_utc` ASC LIMIT ' . $limit,
            ['cutoff_utc' => self::formatDate($cutoff)],
        ));
    }

    private static function minute(DateTimeImmutable $time): DateTimeImmutable
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), 0, 0);
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
