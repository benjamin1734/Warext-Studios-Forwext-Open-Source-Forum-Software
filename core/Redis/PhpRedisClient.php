<?php

declare(strict_types=1);

namespace Forwext\Core\Redis;

use Forwext\Core\Infrastructure\InfrastructureException;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use Throwable;

final readonly class PhpRedisClient implements RedisScriptClient
{
    /** @param object $redis Connected ext-redis client instance. */
    public function __construct(private object $redis)
    {
        if ($redis::class !== 'Redis') {
            throw new InfrastructureException('PhpRedisClient requires an ext-redis Redis instance.');
        }
    }

    public static function connect(
        string $host,
        int $port = 6379,
        float $timeoutSeconds = 2.0,
        #[SensitiveParameter] ?string $password = null,
        ?int $database = null,
    ): self {
        if (!class_exists('Redis')) {
            throw new InfrastructureException('The optional ext-redis extension is not available.');
        }
        if ($host === '' || $port < 1 || $port > 65535 || $timeoutSeconds <= 0.0 || $timeoutSeconds > 30.0) {
            throw new InfrastructureException('Redis connection settings are invalid.');
        }

        try {
            $redis = (new ReflectionClass('Redis'))->newInstance();
            if (self::call($redis, 'connect', [$host, $port, $timeoutSeconds]) !== true) {
                throw new InfrastructureException('Unable to connect to Redis.');
            }
            if ($password !== null && $password !== '' && self::call($redis, 'auth', [$password]) !== true) {
                throw new InfrastructureException('Redis authentication failed.');
            }
            if ($database !== null && ($database < 0 || self::call($redis, 'select', [$database]) !== true)) {
                throw new InfrastructureException('Redis database selection failed.');
            }

            return new self($redis);
        } catch (InfrastructureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis connection failed.', previous: $exception);
        }
    }

    public function get(string $key): ?string
    {
        try {
            $value = self::call($this->redis, 'get', [$key]);
            if ($value === false) {
                return null;
            }
            if (!is_string($value)) {
                throw new InfrastructureException('Redis returned a non-string value.');
            }
            return $value;
        } catch (InfrastructureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis GET failed.', previous: $exception);
        }
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        try {
            $result = $ttlSeconds === null
                ? self::call($this->redis, 'set', [$key, $value])
                : self::call($this->redis, 'setex', [$key, $ttlSeconds, $value]);
            if ($result !== true) {
                throw new InfrastructureException('Redis SET failed.');
            }
        } catch (InfrastructureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis SET failed.', previous: $exception);
        }
    }

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        if ($ttlMilliseconds < 1) {
            throw new InfrastructureException('Redis lock TTL must be positive.');
        }

        try {
            return self::call($this->redis, 'set', [$key, $value, ['nx', 'px' => $ttlMilliseconds]]) === true;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis conditional SET failed.', previous: $exception);
        }
    }

    public function delete(string $key): bool
    {
        try {
            return (int) self::call($this->redis, 'del', [$key]) > 0;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis DEL failed.', previous: $exception);
        }
    }

    public function addSetMember(string $key, string $member, ?int $ttlSeconds = null): void
    {
        try {
            self::call($this->redis, 'sAdd', [$key, $member]);
            if ($ttlSeconds !== null && $ttlSeconds > 0) {
                self::call($this->redis, 'expire', [$key, $ttlSeconds]);
            }
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis set-member update failed.', previous: $exception);
        }
    }

    public function removeSetMember(string $key, string $member): bool
    {
        try {
            return (int) self::call($this->redis, 'sRem', [$key, $member]) > 0;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis set-member removal failed.', previous: $exception);
        }
    }

    public function setMembers(string $key): array
    {
        try {
            $members = self::call($this->redis, 'sMembers', [$key]);
            if (!is_array($members) || array_filter($members, static fn (mixed $member): bool => !is_string($member)) !== []) {
                throw new InfrastructureException('Redis returned an invalid set.');
            }
            /** @var list<string> $members */
            return array_values($members);
        } catch (InfrastructureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis set read failed.', previous: $exception);
        }
    }

    public function deleteIfValueMatches(string $key, string $expectedValue): bool
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        return (int) $this->evaluate($script, [$key], [$expectedValue]) === 1;
    }

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        if ($script === '' || $keys === []) {
            throw new InfrastructureException('Redis script and key list cannot be empty.');
        }
        foreach ($keys as $key) {
            if ($key === '' || str_contains($key, "\0")) {
                throw new InfrastructureException('Redis script contains an invalid key.');
            }
        }

        try {
            return self::call($this->redis, 'eval', [$script, [...$keys, ...$arguments], count($keys)]);
        } catch (Throwable $exception) {
            throw new InfrastructureException('Redis script execution failed.', previous: $exception);
        }
    }

    /** @param list<mixed> $arguments */
    private static function call(object $redis, string $method, array $arguments): mixed
    {
        if (!method_exists($redis, $method)) {
            throw new InfrastructureException(sprintf('ext-redis method "%s" is unavailable.', $method));
        }

        return (new ReflectionMethod($redis, $method))->invokeArgs($redis, $arguments);
    }
}
