<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class DatabaseAuthenticationRateLimiter implements AuthenticationRateLimiter
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function consume(string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || $limit < 1
            || $windowSeconds < 60
            || $windowSeconds > 86400
        ) {
            throw new AuthException('Authentication rate-limit input is invalid.');
        }
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $bucketStart = intdiv($timestamp, $windowSeconds) * $windowSeconds;
        $bucket = (new DateTimeImmutable('@' . $bucketStart))->setTimezone(new DateTimeZone('UTC'));

        return $this->database->transaction(function () use ($fingerprint, $limit, $bucket): bool {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_auth_rate_limits` (`fingerprint`, `bucket_start_utc`, `attempts`) '
                . 'VALUES (:fingerprint, :bucket_start_utc, 1) '
                . 'ON DUPLICATE KEY UPDATE `attempts` = `attempts` + 1',
                [
                    'fingerprint' => $fingerprint,
                    'bucket_start_utc' => $bucket->format('Y-m-d H:i:s.u'),
                ],
            ));
            $attempts = $this->database->fetchValue(new CompiledQuery(
                'SELECT `attempts` FROM `forwext_auth_rate_limits` '
                . 'WHERE `fingerprint` = :fingerprint AND `bucket_start_utc` = :bucket_start_utc',
                [
                    'fingerprint' => $fingerprint,
                    'bucket_start_utc' => $bucket->format('Y-m-d H:i:s.u'),
                ],
            ));
            return (int) $attempts <= $limit;
        });
    }
}
