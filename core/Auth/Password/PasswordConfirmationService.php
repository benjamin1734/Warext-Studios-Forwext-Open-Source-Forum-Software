<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Password;

use Forwext\Core\Auth\Challenge\AuthChallengePurpose;
use Forwext\Core\Auth\Challenge\AuthChallengeTokenStore;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use SensitiveParameter;

final readonly class PasswordConfirmationService
{
    public function __construct(
        private CredentialStore $credentials,
        private PasswordHasher $hasher,
        private AuthChallengeTokenStore $challenges,
        private int $confirmationTtlSeconds = 900,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function confirm(EntityId $userId, #[SensitiveParameter] string $password): ?string
    {
        $credential = $this->credentials->find($userId);
        if ($credential === null) {
            $this->hasher->dummyVerify($password);
            return null;
        }
        if (!$this->hasher->verify($password, $credential->passwordHash)) {
            return null;
        }
        return $this->challenges->issue(
            $userId,
            AuthChallengePurpose::PasswordConfirmation,
            $this->clock->now(),
            $this->confirmationTtlSeconds,
        );
    }

    public function consume(EntityId $userId, string $token): bool
    {
        $grant = $this->challenges->consume(
            $token,
            AuthChallengePurpose::PasswordConfirmation,
            $this->clock->now(),
        );
        return $grant !== null && hash_equals($userId->value(), $grant->userId->value());
    }
}
