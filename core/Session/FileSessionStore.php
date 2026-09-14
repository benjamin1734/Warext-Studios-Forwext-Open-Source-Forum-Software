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
use JsonException;

final readonly class FileSessionStore implements SessionStore
{
    public function __construct(
        private string $directory,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function read(string $sessionId): ?SessionRecord
    {
        $path = $this->path(KeyValidator::session($sessionId));
        if (!is_file($path)) {
            return null;
        }
        if (is_link($path)) {
            throw new InfrastructureException('Session file may not be a symbolic link.');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InfrastructureException('Unable to read session data.');
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfrastructureException('Session data is corrupted.', previous: $exception);
        }
        if (!is_array($decoded) || !is_string($decoded['payload'] ?? null) || !is_string($decoded['expires_at'] ?? null)) {
            throw new InfrastructureException('Session data has an invalid shape.');
        }
        try {
            $expiresAt = new DateTimeImmutable($decoded['expires_at']);
        } catch (Exception $exception) {
            throw new InfrastructureException('Session expiry is invalid.', previous: $exception);
        }
        $record = new SessionRecord($decoded['payload'], $expiresAt);
        if ($record->isExpired($this->clock->now())) {
            @unlink($path);
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
        $path = $this->path($sessionId);
        if (is_link($path)) {
            throw new InfrastructureException('Session file may not be a symbolic link.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new InfrastructureException('Unable to stage session data.');
            }
            if (!@chmod($temporary, 0600)) {
                throw new InfrastructureException('Unable to restrict session data permissions.');
            }
            if (!@rename($temporary, $path)) {
                throw new InfrastructureException('Unable to atomically replace session data.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function delete(string $sessionId): bool
    {
        $path = $this->path(KeyValidator::session($sessionId));
        if (is_link($path)) {
            throw new InfrastructureException('Session file may not be a symbolic link.');
        }
        return is_file($path) ? @unlink($path) : false;
    }

    public function collectGarbage(int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new InfrastructureException('Session garbage-collection limit must be positive.');
        }
        $this->ensureDirectory();
        $deleted = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if ($deleted >= $limit) {
                break;
            }
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (!is_array($decoded) || !is_string($decoded['expires_at'] ?? null)) {
                continue;
            }
            try {
                $expiresAt = new DateTimeImmutable($decoded['expires_at']);
            } catch (Exception) {
                continue;
            }
            if ($expiresAt <= $this->clock->now() && @unlink($path)) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    private function path(string $sessionId): string
    {
        $this->ensureDirectory();
        return $this->directory . '/' . hash('sha256', $sessionId) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new InfrastructureException('Unable to create session directory.');
        }
    }
}
