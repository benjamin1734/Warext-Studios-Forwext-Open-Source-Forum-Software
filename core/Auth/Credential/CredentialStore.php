<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Credential;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface CredentialStore
{
    public function find(EntityId $userId): ?CredentialRecord;

    public function create(EntityId $userId, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord;

    public function rehash(EntityId $userId, int $expectedVersion, string $passwordHash): CredentialRecord;

    public function replacePassword(
        EntityId $userId,
        int $expectedVersion,
        string $passwordHash,
        DateTimeImmutable $changedAt,
    ): CredentialRecord;
}
