<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class EmailVerificationService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private UserRepository $users,
        private EmailVerificationTokenStore $tokens,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function verify(string $token): bool
    {
        $now = $this->clock->now();
        return $this->database->transaction(function () use ($token, $now): bool {
            $grant = $this->tokens->consume($token, $now);
            if ($grant === null) {
                return false;
            }
            $user = $this->users->find($grant->userId);
            if ($user === null || $user->status() !== UserStatus::PendingEmailVerification) {
                throw new RegistrationException('Email verification account state is invalid.');
            }
            $user->changeStatus($grant->targetStatus, $now, reasonCode: 'registration.email_verified');
            $this->users->save($user);
            return true;
        });
    }
}
