<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use DateInterval;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class DatabaseLockManager implements LockManager
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function acquire(string $name, int $ttlSeconds = 30, int $waitMilliseconds = 0): ?LockHandle
    {
        $name = KeyValidator::lock($name);
        if ($ttlSeconds < 1 || $ttlSeconds > 86400 || $waitMilliseconds < 0) {
            throw new InfrastructureException('Lock TTL must be 1..86400 seconds and wait time cannot be negative.');
        }

        $deadline = microtime(true) + ($waitMilliseconds / 1000);
        do {
            $token = bin2hex(random_bytes(16));
            if ($this->tryAcquire($name, $token, $ttlSeconds)) {
                return new DatabaseLockHandle($name, hash('sha256', $name), $token, $this->database);
            }
            if ($waitMilliseconds === 0) {
                return null;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function tryAcquire(string $name, string $token, int $ttlSeconds): bool
    {
        $hash = hash('sha256', $name);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $expires = $now->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $nowValue = $now->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function () use ($name, $token, $hash, $nowValue, $expires): bool {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_locks` (`lock_hash`, `lock_name`, `token`, `expires_at_utc`) '
                . 'VALUES (:lock_hash, :lock_name, :token, :expires_at_utc) '
                . 'ON DUPLICATE KEY UPDATE '
                . '`lock_name` = IF(`expires_at_utc` <= :now_name, VALUES(`lock_name`), `lock_name`), '
                . '`token` = IF(`expires_at_utc` <= :now_token, VALUES(`token`), `token`), '
                . '`expires_at_utc` = IF(`expires_at_utc` <= :now_expiry, VALUES(`expires_at_utc`), `expires_at_utc`)',
                [
                    'lock_hash' => $hash,
                    'lock_name' => $name,
                    'token' => $token,
                    'expires_at_utc' => $expires->format('Y-m-d H:i:s.u'),
                    'now_name' => $nowValue,
                    'now_token' => $nowValue,
                    'now_expiry' => $nowValue,
                ],
            ));

            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `token` FROM `forwext_locks` WHERE `lock_hash` = :lock_hash LIMIT 1 FOR UPDATE',
                ['lock_hash' => $hash],
                requiresTransaction: true,
            ));
            $storedToken = $row['token'] ?? null;

            if (!is_string($storedToken)) {
                throw new InfrastructureException('Database lock row is malformed.');
            }

            return hash_equals($storedToken, $token);
        });
    }
}
