<?php

declare(strict_types=1);

namespace Forwext\Core\Session;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Redis\RedisClient;
use JsonException;

final readonly class RedisSessionStore implements SessionStore
{
    public function __construct(
        private RedisClient $redis,
        private string $prefix = 'forwext:session:',
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function read(string $sessionId): ?SessionRecord
    {
        $sessionId = KeyValidator::session($sessionId);
        $raw = $this->redis->get($this->key($sessionId));
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfrastructureException('Redis session payload is invalid.', previous: $exception);
        }

        if (!is_array($decoded) || !is_string($decoded['payload'] ?? null) || !is_string($decoded['expires_at'] ?? null)) {
            throw new InfrastructureException('Redis session payload has an invalid shape.');
        }

        try {
            $expires = new DateTimeImmutable($decoded['expires_at']);
        } catch (Exception $exception) {
            throw new InfrastructureException('Redis session expiry is invalid.', previous: $exception);
        }

        $record = new SessionRecord($decoded['payload'], $expires);
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

        $expires = $this->clock->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $encoded = json_encode([
            'payload' => $payload,
            'expires_at' => $expires->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->redis->set($this->key($sessionId), $encoded, $ttlSeconds);
    }

    public function delete(string $sessionId): bool
    {
        return $this->redis->delete($this->key(KeyValidator::session($sessionId)));
    }

    public function collectGarbage(int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new InfrastructureException('Session garbage-collection limit must be positive.');
        }

        // Redis expiration handles session garbage collection atomically.
        return 0;
    }

    private function key(string $sessionId): string
    {
        return $this->prefix . hash('sha256', $sessionId);
    }
}
