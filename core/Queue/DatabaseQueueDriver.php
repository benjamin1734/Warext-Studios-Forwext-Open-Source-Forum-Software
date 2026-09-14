<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function push(
        QueueName $queue,
        string $type,
        string $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
    ): JobId {
        $createdAt = $this->clock->now();
        $job = new QueueJob(
            JobId::generate(),
            $queue,
            $type,
            $payload,
            0,
            $maxAttempts,
            $availableAt ?? $createdAt,
            $createdAt,
        );

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_jobs` '
            . '(`job_id`, `queue_name`, `job_type`, `payload`, `attempts`, `max_attempts`, `available_at_utc`, `created_at_utc`) '
            . 'VALUES (:job_id, :queue_name, :job_type, :payload, 0, :max_attempts, :available_at_utc, :created_at_utc)',
            [
                'job_id' => $job->id->value(),
                'queue_name' => $job->queue->value(),
                'job_type' => $job->type,
                'payload' => $job->payload,
                'max_attempts' => $job->maxAttempts,
                'available_at_utc' => self::formatDate($job->availableAt),
                'created_at_utc' => self::formatDate($job->createdAt),
            ],
        ));

        if ($affected !== 1) {
            throw new QueueException('Queue enqueue did not insert exactly one job.');
        }

        return $job->id;
    }

    public function reserve(QueueName $queue, int $visibilityTimeoutSeconds = 60): ?QueueReservation
    {
        if ($visibilityTimeoutSeconds < 1 || $visibilityTimeoutSeconds > 86400) {
            throw new QueueException('Queue visibility timeout must be between 1 and 86400 seconds.');
        }

        $now = $this->clock->now();
        $this->deadLetterExpiredExhausted($queue, $now, 25);

        return $this->database->transaction(function () use ($queue, $visibilityTimeoutSeconds, $now): ?QueueReservation {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `job_id`, `queue_name`, `job_type`, `payload`, `attempts`, `max_attempts`, '
                . '`available_at_utc`, `created_at_utc` FROM `forwext_jobs` '
                . 'WHERE `queue_name` = :queue_name '
                . 'AND `available_at_utc` <= :available_at_utc '
                . 'AND (`reserved_until_utc` IS NULL OR `reserved_until_utc` <= :reservation_expired_at) '
                . 'AND `attempts` < `max_attempts` '
                . 'ORDER BY `available_at_utc` ASC, `created_at_utc` ASC '
                . 'LIMIT 1 FOR UPDATE SKIP LOCKED',
                [
                    'queue_name' => $queue->value(),
                    'available_at_utc' => self::formatDate($now),
                    'reservation_expired_at' => self::formatDate($now),
                ],
                requiresTransaction: true,
            ));

            if ($row === null) {
                return null;
            }

            $job = $this->hydrateJob($row, incrementAttempt: true);
            $token = bin2hex(random_bytes(16));
            $reservedUntil = $now->add(new DateInterval('PT' . $visibilityTimeoutSeconds . 'S'));
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_jobs` SET `attempts` = :attempts, '
                . '`reservation_token` = :reservation_token, `reserved_until_utc` = :reserved_until_utc '
                . 'WHERE `job_id` = :job_id',
                [
                    'attempts' => $job->attempts,
                    'reservation_token' => $token,
                    'reserved_until_utc' => self::formatDate($reservedUntil),
                    'job_id' => $job->id->value(),
                ],
            ));

            if ($affected !== 1) {
                throw new QueueException('Queue reservation update did not affect exactly one row.');
            }

            return new QueueReservation($job, $token, $reservedUntil);
        });
    }

    public function acknowledge(QueueReservation $reservation): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_jobs` '
            . 'WHERE `job_id` = :job_id AND `reservation_token` = :reservation_token',
            [
                'job_id' => $reservation->job->id->value(),
                'reservation_token' => $reservation->token,
            ],
        ));

        if ($affected !== 1) {
            throw new QueueException('Queue acknowledgement lost reservation ownership.');
        }
    }

    public function retry(QueueReservation $reservation, int $delaySeconds = 0): void
    {
        if ($delaySeconds < 0 || $delaySeconds > 604800) {
            throw new QueueException('Queue retry delay must be between 0 and 604800 seconds.');
        }
        if ($reservation->job->attempts >= $reservation->job->maxAttempts) {
            $this->fail($reservation, 'max_attempts_exhausted');
            return;
        }

        $availableAt = $this->clock->now()->add(new DateInterval('PT' . $delaySeconds . 'S'));
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_jobs` SET `available_at_utc` = :available_at_utc, '
            . '`reservation_token` = NULL, `reserved_until_utc` = NULL '
            . 'WHERE `job_id` = :job_id AND `reservation_token` = :reservation_token',
            [
                'available_at_utc' => self::formatDate($availableAt),
                'job_id' => $reservation->job->id->value(),
                'reservation_token' => $reservation->token,
            ],
        ));

        if ($affected !== 1) {
            throw new QueueException('Queue retry lost reservation ownership.');
        }
    }

    public function fail(QueueReservation $reservation, string $failureCode): void
    {
        self::validateFailureCode($failureCode);

        $this->database->transaction(function () use ($reservation, $failureCode): void {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `job_id` FROM `forwext_jobs` '
                . 'WHERE `job_id` = :job_id AND `reservation_token` = :reservation_token '
                . 'LIMIT 1 FOR UPDATE',
                [
                    'job_id' => $reservation->job->id->value(),
                    'reservation_token' => $reservation->token,
                ],
                requiresTransaction: true,
            ));

            if ($row === null) {
                throw new QueueException('Queue failure handling lost reservation ownership.');
            }

            $this->insertFailedJob($reservation->job, $failureCode, $this->clock->now());
            $affected = $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_jobs` '
                . 'WHERE `job_id` = :job_id AND `reservation_token` = :reservation_token',
                [
                    'job_id' => $reservation->job->id->value(),
                    'reservation_token' => $reservation->token,
                ],
            ));

            if ($affected !== 1) {
                throw new QueueException('Queue failure cleanup did not affect exactly one job.');
            }
        });
    }

    private function deadLetterExpiredExhausted(QueueName $queue, DateTimeImmutable $now, int $limit): void
    {
        for ($processed = 0; $processed < $limit; ++$processed) {
            $moved = $this->database->transaction(function () use ($queue, $now): bool {
                $row = $this->database->fetchOne(new CompiledQuery(
                    'SELECT `job_id`, `queue_name`, `job_type`, `payload`, `attempts`, `max_attempts`, '
                    . '`available_at_utc`, `created_at_utc` FROM `forwext_jobs` '
                    . 'WHERE `queue_name` = :queue_name '
                    . 'AND `attempts` >= `max_attempts` '
                    . 'AND `reserved_until_utc` IS NOT NULL '
                    . 'AND `reserved_until_utc` <= :expired_at_utc '
                    . 'ORDER BY `reserved_until_utc` ASC '
                    . 'LIMIT 1 FOR UPDATE SKIP LOCKED',
                    [
                        'queue_name' => $queue->value(),
                        'expired_at_utc' => self::formatDate($now),
                    ],
                    requiresTransaction: true,
                ));

                if ($row === null) {
                    return false;
                }

                $job = $this->hydrateJob($row, incrementAttempt: false);
                $this->insertFailedJob($job, 'reservation_timeout_max_attempts', $now);
                $affected = $this->database->execute(new CompiledQuery(
                    'DELETE FROM `forwext_jobs` WHERE `job_id` = :job_id',
                    ['job_id' => $job->id->value()],
                ));

                if ($affected !== 1) {
                    throw new QueueException('Expired queue job cleanup did not affect exactly one row.');
                }

                return true;
            });

            if (!$moved) {
                return;
            }
        }
    }

    private function insertFailedJob(QueueJob $job, string $failureCode, DateTimeImmutable $failedAt): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_failed_jobs` '
            . '(`job_id`, `queue_name`, `job_type`, `payload`, `attempts`, `max_attempts`, '
            . '`failure_code`, `failed_at_utc`, `created_at_utc`) '
            . 'VALUES (:job_id, :queue_name, :job_type, :payload, :attempts, :max_attempts, '
            . ':failure_code, :failed_at_utc, :created_at_utc)',
            [
                'job_id' => $job->id->value(),
                'queue_name' => $job->queue->value(),
                'job_type' => $job->type,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'max_attempts' => $job->maxAttempts,
                'failure_code' => $failureCode,
                'failed_at_utc' => self::formatDate($failedAt),
                'created_at_utc' => self::formatDate($job->createdAt),
            ],
        ));

        if ($affected !== 1) {
            throw new QueueException('Failed-job insertion did not affect exactly one row.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateJob(array $row, bool $incrementAttempt): QueueJob
    {
        foreach (['job_id', 'queue_name', 'job_type', 'payload', 'available_at_utc', 'created_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new QueueException('Queue row is malformed.');
            }
        }

        $attempts = self::unsignedInteger($row['attempts'] ?? null, 'attempts');
        $maxAttempts = self::unsignedInteger($row['max_attempts'] ?? null, 'max_attempts');
        if ($incrementAttempt) {
            ++$attempts;
        }

        return new QueueJob(
            JobId::fromString($row['job_id']),
            QueueName::fromString($row['queue_name']),
            $row['job_type'],
            $row['payload'],
            $attempts,
            $maxAttempts,
            self::parseDate($row['available_at_utc']),
            self::parseDate($row['created_at_utc']),
        );
    }

    private static function unsignedInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;
        } else {
            throw new QueueException(sprintf('Queue row field "%s" is invalid.', $field));
        }

        if ($number < 0 || $number > 100) {
            throw new QueueException(sprintf('Queue row field "%s" is outside the allowed range.', $field));
        }

        return $number;
    }

    private static function validateFailureCode(string $failureCode): void
    {
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $failureCode) !== 1) {
            throw new QueueException('Queue failure code is invalid.');
        }
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $date, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d H:i:s.u') !== $date) {
            throw new QueueException('Queue row contains an invalid UTC timestamp.');
        }

        return $parsed;
    }
}
