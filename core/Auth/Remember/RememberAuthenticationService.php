<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Remember;

use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class RememberAuthenticationService
{
    public function __construct(
        private RememberTokenService $rememberTokens,
        private UserRepository $users,
        private AuthSessionManager $sessions,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function restore(string $rememberToken, ?string $previousSessionId = null): ?RememberAuthenticationResult
    {
        $grant = $this->rememberTokens->consumeAndRotate($rememberToken, $this->clock->now());
        if ($grant === null) {
            return null;
        }
        $user = $this->users->find($grant->userId);
        if ($user === null || !$user->status()->canAuthenticateNormally()) {
            $this->rememberTokens->revokeUser($grant->userId, $this->clock->now());
            return null;
        }
        $sessionId = $this->sessions->establish(
            $grant->userId,
            $grant->deviceId,
            $grant->credentialVersion,
            $previousSessionId,
        );
        return new RememberAuthenticationResult($grant->userId, $sessionId, $grant->replacementToken);
    }
}
