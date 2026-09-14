<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Credential;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use SensitiveParameter;

interface CredentialProvisioner
{
    public function provision(
        EntityId $userId,
        #[SensitiveParameter] string $password,
        DateTimeImmutable $now,
    ): CredentialRecord;
}
