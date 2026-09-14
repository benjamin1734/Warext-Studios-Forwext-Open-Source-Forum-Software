<?php

declare(strict_types=1);

namespace Forwext\Core\Session;

interface SessionStore
{
    public function read(string $sessionId): ?SessionRecord;

    public function write(string $sessionId, string $payload, int $ttlSeconds): void;

    public function delete(string $sessionId): bool;

    public function collectGarbage(int $limit = 1000): int;
}
