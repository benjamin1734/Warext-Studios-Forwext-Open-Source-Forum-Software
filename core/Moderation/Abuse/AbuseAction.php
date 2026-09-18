<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

enum AbuseAction: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Reject = 'reject';

    public function severity(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Review => 1,
            self::Reject => 2,
        };
    }
}
