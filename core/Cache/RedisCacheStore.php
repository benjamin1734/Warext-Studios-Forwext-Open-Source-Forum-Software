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
use Forwext\Core\Redis\RedisClient;
use JsonException;

final readonly class RedisCacheStore implements CacheStore
{
    public function __construct(
        private RedisClient $redis,
        private string $prefix = 'forwext:cache:',
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function get(string $key): ?CacheEntry
    {
        $key = KeyValidator::cache($key);
        $raw = $this->redis->get($this->key($key));
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfrastructureException('Redis cache payload is invalid.', previous: $exception);
        }

        if (!is_array($decoded) || !is_string($decoded['value'] ?? null)) {
            throw new InfrastructureException('Redis cache payload has an invalid shape.');
        }

        $expires = null;
        if (isset($decoded['expires_at'])) {
            if (!is_string($decoded['expires_at'])) {
                throw new InfrastructureException('Redis cache expiry is invalid.');
            }
            try {
                $expires = new DateTimeImmutable($decoded['expires_at']);
            } catch (Exception $exception) {
                throw new InfrastructureException('Redis cache expiry is invalid.', previous: $exception);
            }
        }

        $tags = is_array($decoded['tags'] ?? null) ? array_values($decoded['tags']) : [];
        if (array_filter($tags, static fn (mixed $tag): bool => !is_string($tag)) !== []) {
            throw new InfrastructureException('Redis cache tags are invalid.');
        }
        /** @var list<string> $tags */

        $entry = new CacheEntry($decoded['value'], $expires, $tags);
        if ($entry->isExpired($this->clock->now())) {
            $this->delete($key);
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

        $existing = $this->get($key);
        if ($existing !== null) {
            foreach ($existing->tags as $oldTag) {
                $this->redis->removeSetMember($this->tagKey($oldTag), $key);
            }
        }

        $normalizedTags = [];
        foreach ($tags as $tag) {
            $normalizedTags[] = KeyValidator::tag($tag);
        }
        $normalizedTags = array_values(array_unique($normalizedTags));
        $expires = $ttlSeconds === null ? null : $this->clock->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));
        $payload = json_encode([
            'value' => $value,
            'expires_at' => $expires?->format(DATE_ATOM),
            'tags' => $normalizedTags,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->redis->set($this->key($key), $payload, $ttlSeconds);
        foreach ($normalizedTags as $tag) {
            // Tag indexes intentionally have no TTL: stale members are removed on overwrite/delete/invalidation.
            $this->redis->addSetMember($this->tagKey($tag), $key);
        }
    }

    public function delete(string $key): bool
    {
        $key = KeyValidator::cache($key);
        $entry = $this->getWithoutExpiryCleanup($key);
        $deleted = $this->redis->delete($this->key($key));

        if ($entry !== null) {
            foreach ($entry->tags as $tag) {
                $this->redis->removeSetMember($this->tagKey($tag), $key);
            }
        }

        return $deleted;
    }

    public function invalidateTag(string $tag): int
    {
        $tag = KeyValidator::tag($tag);
        $members = $this->redis->setMembers($this->tagKey($tag));
        $deleted = 0;

        foreach ($members as $key) {
            if ($this->delete($key)) {
                ++$deleted;
            }
        }

        $this->redis->delete($this->tagKey($tag));
        return $deleted;
    }

    private function getWithoutExpiryCleanup(string $key): ?CacheEntry
    {
        $raw = $this->redis->get($this->key($key));
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_string($decoded['value'] ?? null) || !is_array($decoded['tags'] ?? null)) {
            return null;
        }

        $tags = array_values($decoded['tags']);
        if (array_filter($tags, static fn (mixed $tag): bool => !is_string($tag)) !== []) {
            return null;
        }
        /** @var list<string> $tags */

        return new CacheEntry($decoded['value'], null, $tags);
    }

    private function key(string $key): string
    {
        return $this->prefix . hash('sha256', $key);
    }

    private function tagKey(string $tag): string
    {
        return $this->prefix . 'tag:' . hash('sha256', $tag);
    }
}
