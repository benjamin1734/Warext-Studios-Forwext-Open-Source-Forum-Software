<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Challenge;

enum MfaChallengePurpose: string
{
    case Login = 'login';
    case SensitiveAction = 'sensitive_action';
}
