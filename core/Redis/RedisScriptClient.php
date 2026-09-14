<?php

declare(strict_types=1);

namespace Forwext\Core\Redis;

interface RedisScriptClient extends RedisClient
{
    /**
     * @param list<string> $keys
     * @param list<string|int|float> $arguments
     */
    public function evaluate(string $script, array $keys, array $arguments = []): mixed;
}
