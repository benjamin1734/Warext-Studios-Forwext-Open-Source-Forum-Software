<?php

declare(strict_types=1);

namespace Forwext\Core\Cache;

interface CacheStore
{
    public function get(string $key): ?CacheEntry;

    /** @param list<string> $tags */
    public function put(string $key, string $value, ?int $ttlSeconds = null, array $tags = []): void;

    public function delete(string $key): bool;

    public function invalidateTag(string $tag): int;
}
