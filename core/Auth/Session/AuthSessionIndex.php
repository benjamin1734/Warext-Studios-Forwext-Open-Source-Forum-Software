<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AuthSessionIndex
{
    public function find(string $sessionHash): ?AuthSessionIndexRecord;

    public function register(AuthSessionIndexRecord $record): void;

    public function touch(string $sessionHash, DateTimeImmutable $at): void;

    public function revoke(string $sessionHash, DateTimeImmutable $at): void;

    public function revokeForUser(EntityId $userId, string $sessionHash, DateTimeImmutable $at): bool;

    public function revokeOthers(EntityId $userId, string $exceptSessionHash, DateTimeImmutable $at): int;

    /** @return list<AuthSessionIndexRecord> */
    public function activeForUser(EntityId $userId, DateTimeImmutable $now, int $limit = 50): array;
}
