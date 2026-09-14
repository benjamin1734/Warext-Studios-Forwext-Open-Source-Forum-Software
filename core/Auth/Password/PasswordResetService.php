<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Password;

use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Challenge\AuthChallengePurpose;
use Forwext\Core\Auth\Challenge\AuthChallengeTokenStore;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use SensitiveParameter;

final readonly class PasswordResetService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private CredentialStore $credentials,
        private PasswordHasher $hasher,
        private AuthChallengeTokenStore $challenges,
        private RememberTokenService $rememberTokens,
        private int $resetTtlSeconds = 3600,
        private Clock $clock = new SystemClock(),
    ) {
        if ($resetTtlSeconds < 300 || $resetTtlSeconds > 86400) {
            throw new AuthException('Password reset TTL is outside safe bounds.');
        }
    }

    public function issueForUser(EntityId $userId): string
    {
        UserId::assert($userId);
        if ($this->credentials->find($userId) === null) {
            throw new AuthException('Password credential does not exist.');
        }
        return $this->challenges->issue(
            $userId,
            AuthChallengePurpose::PasswordReset,
            $this->clock->now(),
            $this->resetTtlSeconds,
        );
    }

    public function reset(string $token, #[SensitiveParameter] string $newPassword): bool
    {
        $now = $this->clock->now();
        $newHash = $this->hasher->hash($newPassword);

        return $this->database->transaction(function () use ($token, $newHash, $now): bool {
            $grant = $this->challenges->consume($token, AuthChallengePurpose::PasswordReset, $now);
            if ($grant === null) {
                return false;
            }
            $current = $this->credentials->find($grant->userId);
            if ($current === null) {
                throw new AuthException('Password credential disappeared during reset.');
            }
            $this->credentials->replacePassword($grant->userId, $current->version, $newHash, $now);
            $this->rememberTokens->revokeUser($grant->userId, $now);
            return true;
        });
    }
}
