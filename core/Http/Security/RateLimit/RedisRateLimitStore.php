<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

use Forwext\Core\Redis\RedisScriptClient;

final readonly class RedisRateLimitStore implements RateLimitStore
{
    public function __construct(
        private RedisScriptClient $redis,
        private string $prefix = 'forwext:rate-limit:',
    ) {
        if ($prefix === '' || str_contains($prefix, "\0")) {
            throw new RateLimitException('Redis rate-limit prefix is invalid.');
        }
    }

    public function consume(string $key, RateLimitPolicy $policy, int $now): RateLimitResult
    {
        if ($key === '' || strlen($key) > 2048 || $now < 0) {
            throw new RateLimitException('Rate-limit identity key or timestamp is invalid.');
        }

        $script = <<<'LUA'
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])

local bucket_start = tonumber(redis.call('HGET', KEYS[1], 'start'))
local count = tonumber(redis.call('HGET', KEYS[1], 'count'))

if bucket_start == nil or count == nil or now >= bucket_start + window then
    bucket_start = now
    count = 0
end

local reset_at = bucket_start + window

if count >= limit then
    redis.call('HSET', KEYS[1], 'start', bucket_start, 'count', count)
    redis.call('EXPIREAT', KEYS[1], reset_at + 1)
    return {0, limit, 0, reset_at}
end

count = count + 1
redis.call('HSET', KEYS[1], 'start', bucket_start, 'count', count)
redis.call('EXPIREAT', KEYS[1], reset_at + 1)

return {1, limit, math.max(0, limit - count), reset_at}
LUA;

        $result = $this->redis->evaluate(
            $script,
            [$this->prefix . hash('sha256', $policy->name . "\0" . $key)],
            [(string) $now, (string) $policy->windowSeconds, (string) $policy->limit],
        );

        if (
            !is_array($result)
            || count($result) !== 4
            || !isset($result[0], $result[1], $result[2], $result[3])
        ) {
            throw new RateLimitException('Redis returned an invalid rate-limit result.');
        }

        $values = [];
        foreach ($result as $value) {
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)) {
                throw new RateLimitException('Redis returned a non-integer rate-limit result.');
            }
            $values[] = (int) $value;
        }

        [$allowed, $limit, $remaining, $resetAt] = $values;
        if (
            !in_array($allowed, [0, 1], true)
            || $limit !== $policy->limit
            || $remaining < 0
            || $remaining > $policy->limit
            || $resetAt < $now
        ) {
            throw new RateLimitException('Redis returned an inconsistent rate-limit result.');
        }

        return new RateLimitResult($allowed === 1, $limit, $remaining, $resetAt);
    }
}
