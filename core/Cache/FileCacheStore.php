<?php

declare(strict_types=1);

namespace Forwext\Core\Cache;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Infrastructure\SystemClock;
use JsonException;

final readonly class FileCacheStore implements CacheStore
{
    public function __construct(private string $directory, private Clock $clock = new SystemClock())
    {
    }

    public function get(string $key): ?CacheEntry
    {
        $key = KeyValidator::cache($key);
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        if (is_link($path)) {
            throw new InfrastructureException('Cache entry may not be a symbolic link.');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InfrastructureException('Unable to read cache entry.');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfrastructureException('Cache entry is corrupted.', previous: $exception);
        }
        if (!is_array($decoded) || ($decoded['key'] ?? null) !== $key || !is_string($decoded['value_b64'] ?? null)) {
            throw new InfrastructureException('Cache entry has an invalid shape.');
        }
        $value = base64_decode($decoded['value_b64'], true);
        if ($value === false) {
            throw new InfrastructureException('Cache value encoding is invalid.');
        }
        $expiresAt = null;
        if (isset($decoded['expires_at'])) {
            if (!is_string($decoded['expires_at'])) {
                throw new InfrastructureException('Cache expiry is invalid.');
            }
            try {
                $expiresAt = new DateTimeImmutable($decoded['expires_at']);
            } catch (Exception $exception) {
                throw new InfrastructureException('Cache expiry is invalid.', previous: $exception);
            }
        }
        $tags = $decoded['tags'] ?? [];
        if (!is_array($tags) || array_filter($tags, static fn (mixed $tag): bool => !is_string($tag)) !== []) {
            throw new InfrastructureException('Cache tags are invalid.');
        }
        /** @var list<string> $tags */
        $entry = new CacheEntry($value, $expiresAt, array_values($tags));
        if ($entry->isExpired($this->clock->now())) {
            @unlink($path);
            return null;
        }
        return $entry;
    }

    public function put(string $key, string $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $key = KeyValidator::cache($key);
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            throw new InfrastructureException('Cache TTL must be positive.');
        }
        $normalizedTags = [];
        foreach ($tags as $tag) {
            $normalizedTags[] = KeyValidator::tag($tag);
        }
        $normalizedTags = array_values(array_unique($normalizedTags));
        $expiresAt = $ttlSeconds === null ? null : $this->clock->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $payload = json_encode([
            'key' => $key,
            'value_b64' => base64_encode($value),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'tags' => $normalizedTags,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->path($key), $payload);
    }

    public function delete(string $key): bool
    {
        $path = $this->path(KeyValidator::cache($key));
        if (is_link($path)) {
            throw new InfrastructureException('Cache entry may not be a symbolic link.');
        }
        return is_file($path) ? @unlink($path) : false;
    }

    public function invalidateTag(string $tag): int
    {
        $tag = KeyValidator::tag($tag);
        $this->ensureDirectory();
        $deleted = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($decoded) && is_array($decoded['tags'] ?? null) && in_array($tag, $decoded['tags'], true) && @unlink($path)) {
                ++$deleted;
            }
        }
        return $deleted;
    }

    private function path(string $key): string
    {
        $this->ensureDirectory();
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new InfrastructureException('Unable to create cache directory.');
        }
    }

    private function atomicWrite(string $path, string $payload): void
    {
        if (is_link($path)) {
            throw new InfrastructureException('Cache entry may not be a symbolic link.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new InfrastructureException('Unable to stage cache entry.');
            }
            if (!@chmod($temporary, 0600)) {
                throw new InfrastructureException('Unable to restrict cache entry permissions.');
            }
            if (!@rename($temporary, $path)) {
                throw new InfrastructureException('Unable to atomically replace cache entry.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
