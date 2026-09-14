<?php

declare(strict_types=1);

namespace Forwext\Core\Cache;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class DatabaseCacheStore implements CacheStore
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function get(string $key): ?CacheEntry
    {
        $key = KeyValidator::cache($key);
        $hash = hash('sha256', $key);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `cache_value`, `expires_at_utc` FROM `forwext_cache` WHERE `key_hash` = :key_hash LIMIT 1',
            ['key_hash' => $hash],
        ));

        if ($row === null) {
            return null;
        }

        $value = $row['cache_value'] ?? null;
        if (!is_string($value)) {
            throw new InfrastructureException('Database cache returned an invalid value.');
        }

        $expiresAt = $this->parseNullableDate($row['expires_at_utc'] ?? null);
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            $this->delete($key);
            return null;
        }

        $tagRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `tag_name` FROM `forwext_cache_tags` WHERE `key_hash` = :key_hash ORDER BY `tag_name` ASC',
            ['key_hash' => $hash],
        ));
        $tags = [];
        foreach ($tagRows as $tagRow) {
            $tag = $tagRow['tag_name'] ?? null;
            if (!is_string($tag)) {
                throw new InfrastructureException('Database cache returned an invalid tag.');
            }
            $tags[] = KeyValidator::tag($tag);
        }

        return new CacheEntry($value, $expiresAt, $tags);
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
        $hash = hash('sha256', $key);
        $expiresAt = $ttlSeconds === null ? null : $this->clock->now()->add(new DateInterval('PT' . $ttlSeconds . 'S'));

        $this->database->transaction(function () use ($key, $value, $hash, $expiresAt, $normalizedTags): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_cache` (`key_hash`, `cache_key`, `cache_value`, `expires_at_utc`) '
                . 'VALUES (:key_hash, :cache_key, :cache_value, :expires_at_utc) '
                . 'ON DUPLICATE KEY UPDATE `cache_key` = VALUES(`cache_key`), `cache_value` = VALUES(`cache_value`), '
                . '`expires_at_utc` = VALUES(`expires_at_utc`)',
                [
                    'key_hash' => $hash,
                    'cache_key' => $key,
                    'cache_value' => $value,
                    'expires_at_utc' => $expiresAt === null ? null : self::formatDate($expiresAt),
                ],
            ));

            $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_cache_tags` WHERE `key_hash` = :key_hash',
                ['key_hash' => $hash],
            ));

            foreach ($normalizedTags as $tag) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_cache_tags` (`tag_hash`, `tag_name`, `key_hash`) '
                    . 'VALUES (:tag_hash, :tag_name, :key_hash)',
                    [
                        'tag_hash' => hash('sha256', $tag),
                        'tag_name' => $tag,
                        'key_hash' => $hash,
                    ],
                ));
            }
        });
    }

    public function delete(string $key): bool
    {
        $hash = hash('sha256', KeyValidator::cache($key));

        return $this->database->transaction(function () use ($hash): bool {
            $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_cache_tags` WHERE `key_hash` = :key_hash',
                ['key_hash' => $hash],
            ));
            return $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_cache` WHERE `key_hash` = :key_hash',
                ['key_hash' => $hash],
            )) > 0;
        });
    }

    public function invalidateTag(string $tag): int
    {
        $tag = KeyValidator::tag($tag);
        $tagHash = hash('sha256', $tag);

        return $this->database->transaction(function () use ($tagHash): int {
            $rows = $this->database->fetchAll(new CompiledQuery(
                'SELECT `key_hash` FROM `forwext_cache_tags` WHERE `tag_hash` = :tag_hash',
                ['tag_hash' => $tagHash],
            ));
            $deleted = 0;

            foreach ($rows as $row) {
                $keyHash = $row['key_hash'] ?? null;
                if (!is_string($keyHash) || preg_match('/^[a-f0-9]{64}$/D', $keyHash) !== 1) {
                    throw new InfrastructureException('Database cache tag index is malformed.');
                }
                $this->database->execute(new CompiledQuery(
                    'DELETE FROM `forwext_cache_tags` WHERE `key_hash` = :key_hash',
                    ['key_hash' => $keyHash],
                ));
                if ($this->database->execute(new CompiledQuery(
                    'DELETE FROM `forwext_cache` WHERE `key_hash` = :key_hash',
                    ['key_hash' => $keyHash],
                )) > 0) {
                    ++$deleted;
                }
            }

            return $deleted;
        });
    }

    private function parseNullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InfrastructureException('Database cache expiry is invalid.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new InfrastructureException('Database cache expiry is invalid.');
        }
        return $parsed;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
