<?php

declare(strict_types=1);

namespace Forwext\Core\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function read(string $sessionId): ?SessionRecord
    {
        $sessionId = KeyValidator::session($sessionId);
        $hash = hash('sha256', $sessionId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `payload`, `expires_at_utc` FROM `forwext_sessions` WHERE `session_hash` = :session_hash LIMIT 1',
            ['session_hash' => $hash],
        ));
        if ($row === null) {
            return null;
        }

        $payload = $row['payload'] ?? null;
        $expires = $row['expires_at_utc'] ?? null;
        if (!is_string($payload) || !is_string($expires)) {
            throw new InfrastructureException('Database session row is malformed.');
        }
        $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $expires, new DateTimeZone('UTC'));
        if (!$expiresAt instanceof DateTimeImmutable) {
            throw new InfrastructureException('Database session expiry is invalid.');
        }

        $record = new SessionRecord($payload, $expiresAt);
        if ($record->isExpired($this->clock->now())) {
            $this->delete($sessionId);
            return null;
        }
        return $record;
    }

    public function write(string $sessionId, string $payload, int $ttlSeconds): void
    {
        $sessionId = KeyValidator::session($sessionId);
        if ($ttlSeconds < 1) {
            throw new InfrastructureException('Session TTL must be positive.');
        }
        $expiresAt = $this->clock->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_sessions` (`session_hash`, `payload`, `expires_at_utc`) '
            . 'VALUES (:session_hash, :payload, :expires_at_utc) '
            . 'ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `expires_at_utc` = VALUES(`expires_at_utc`)',
            [
                'session_hash' => hash('sha256', $sessionId),
                'payload' => $payload,
                'expires_at_utc' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ],
        ));
    }

    public function delete(string $sessionId): bool
    {
        $sessionId = KeyValidator::session($sessionId);
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_sessions` WHERE `session_hash` = :session_hash',
            ['session_hash' => hash('sha256', $sessionId)],
        )) > 0;
    }

    public function collectGarbage(int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InfrastructureException('Session garbage-collection limit must be between 1 and 10000.');
        }
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_sessions` WHERE `expires_at_utc` <= :now ORDER BY `expires_at_utc` ASC LIMIT ' . $limit,
            ['now' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')],
        ));
    }
}
