<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ApiV1CredentialRepository
{
    public function findBySecretHash(string $secretHash): ?ApiV1CredentialRecord;

    public function save(ApiV1CredentialRecord $record): void;

    public function markUsed(EntityId $credentialId, DateTimeImmutable $at): void;

    public function revoke(EntityId $credentialId, EntityId $ownerUserId, DateTimeImmutable $at): void;
}
