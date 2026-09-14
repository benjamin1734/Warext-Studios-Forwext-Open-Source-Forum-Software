<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Credential;

use DateTimeImmutable;
use Forwext\Core\Auth\Password\PasswordHasher;
use Forwext\Core\Domain\Entity\EntityId;
use SensitiveParameter;

final readonly class PasswordCredentialProvisioner implements CredentialProvisioner
{
    public function __construct(
        private CredentialStore $credentials,
        private PasswordHasher $hasher,
    ) {
    }

    public function provision(
        EntityId $userId,
        #[SensitiveParameter] string $password,
        DateTimeImmutable $now,
    ): CredentialRecord {
        return $this->credentials->create($userId, $this->hasher->hash($password), $now);
    }
}
