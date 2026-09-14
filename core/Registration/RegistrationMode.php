<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

enum RegistrationMode: string
{
    case Open = 'open';
    case Approval = 'approval';
    case InviteOnly = 'invite_only';
    case Closed = 'closed';

    public function requiresApproval(): bool
    {
        return $this === self::Approval;
    }

    public function requiresInvite(): bool
    {
        return $this === self::InviteOnly;
    }
}
