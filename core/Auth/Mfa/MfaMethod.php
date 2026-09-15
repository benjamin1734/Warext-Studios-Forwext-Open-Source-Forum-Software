<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa;

enum MfaMethod: string
{
    case Totp = 'totp';
    case RecoveryCode = 'recovery_code';
    case Passkey = 'passkey';
}
