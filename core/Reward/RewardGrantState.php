<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

enum RewardGrantState: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Revoked = 'revoked';
}
