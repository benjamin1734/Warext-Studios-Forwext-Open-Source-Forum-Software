<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

enum UserStatus: string
{
    case PendingEmailVerification = 'pending_email';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Deactivated = 'deactivated';
    case DeletionPending = 'deletion_pending';

    public function canAuthenticateNormally(): bool
    {
        return $this === self::Active;
    }

    public function isModerationRestricted(): bool
    {
        return $this === self::Suspended || $this === self::Banned;
    }
}
