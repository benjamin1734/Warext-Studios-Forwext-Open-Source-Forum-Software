<?php

declare(strict_types=1);

namespace Forwext\Core\Redis;

interface RedisClient
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, ?int $ttlSeconds = null): void;

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool;

    public function delete(string $key): bool;

    public function addSetMember(string $key, string $member, ?int $ttlSeconds = null): void;

    public function removeSetMember(string $key, string $member): bool;

    /** @return list<string> */
    public function setMembers(string $key): array;

    public function deleteIfValueMatches(string $key, string $expectedValue): bool;
}
