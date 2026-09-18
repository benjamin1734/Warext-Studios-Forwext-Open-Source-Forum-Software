<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseSupportSubmissionRateLimiter implements SupportSubmissionRateLimiter
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function consume(
        string $scope,
        string $fingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $scope) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || $limit < 1
            || $windowSeconds < 60
            || $windowSeconds > 86400
        ) {
            throw new InvalidArgumentException('Support submission rate-limit input is invalid.');
        }

        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $bucketStart = intdiv($timestamp, $windowSeconds) * $windowSeconds;
        $bucket = (new DateTimeImmutable('@' . $bucketStart))->setTimezone(new DateTimeZone('UTC'));

        return $this->database->transaction(function () use (
            $scope,
            $fingerprint,
            $limit,
            $bucket,
        ): bool {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_support_submission_rate_limits '
                . '(scope_name,fingerprint,bucket_start_utc,attempts) '
                . 'VALUES (:scope_name,:fingerprint,:bucket_start_utc,1) '
                . 'ON DUPLICATE KEY UPDATE attempts=attempts+1',
                [
                    'scope_name'=>$scope,
                    'fingerprint'=>$fingerprint,
                    'bucket_start_utc'=>$bucket->format('Y-m-d H:i:s.u'),
                ],
            ));
            $attempts = $this->database->fetchValue(new CompiledQuery(
                'SELECT attempts FROM forwext_support_submission_rate_limits '
                . 'WHERE scope_name=:scope_name AND fingerprint=:fingerprint '
                . 'AND bucket_start_utc=:bucket_start_utc',
                [
                    'scope_name'=>$scope,
                    'fingerprint'=>$fingerprint,
                    'bucket_start_utc'=>$bucket->format('Y-m-d H:i:s.u'),
                ],
            ));
            return (int) $attempts <= $limit;
        });
    }
}
