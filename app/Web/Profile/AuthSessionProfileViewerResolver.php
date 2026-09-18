<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserAuthenticationAvailability;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\Request;
use InvalidArgumentException;

final readonly class AuthSessionProfileViewerResolver implements ProfileViewerResolver
{
    public function __construct(
        private AuthSessionManager $sessions,
        private UserRepository $users,
        private string $cookieName,
        private ?UserAuthenticationAvailability $availability = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $cookieName) !== 1) {
            throw new InvalidArgumentException('Authentication session cookie name is invalid.');
        }
    }

    public function resolve(Request $request): ?EntityId
    {
        $sessionId = $request->cookie($this->cookieName);
        if ($sessionId === null || preg_match('/^s_[A-Za-z0-9_-]{43}$/D', $sessionId) !== 1) {
            return null;
        }

        $identity = $this->sessions->resolve($sessionId);
        if ($identity === null) {
            return null;
        }

        $user = $this->users->find($identity->userId);
        if ($user === null || !$user->status()->canAuthenticateNormally()
            || ($this->availability !== null && !$this->availability->allows($identity->userId))
        ) {
            return null;
        }

        return $identity->userId;
    }
}
