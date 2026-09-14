<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

final readonly class UserStatusTransitionPolicy
{
    /** @var array<string, list<UserStatus>> */
    private const TRANSITIONS = [
        'pending_email' => [
            UserStatus::PendingApproval,
            UserStatus::Active,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ],
        'pending_approval' => [
            UserStatus::Active,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ],
        'active' => [
            UserStatus::Suspended,
            UserStatus::Banned,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ],
        'suspended' => [
            UserStatus::Active,
            UserStatus::Banned,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ],
        'banned' => [
            UserStatus::Active,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ],
        'deactivated' => [
            UserStatus::Active,
            UserStatus::DeletionPending,
        ],
        'deletion_pending' => [
            UserStatus::Active,
            UserStatus::Deactivated,
        ],
    ];

    public function allows(UserStatus $from, UserStatus $to): bool
    {
        return $from !== $to && in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertAllowed(UserStatus $from, UserStatus $to): void
    {
        if (!$this->allows($from, $to)) {
            throw new UserStatusTransitionException(sprintf(
                'User status transition from "%s" to "%s" is not allowed.',
                $from->value,
                $to->value,
            ));
        }
    }
}
