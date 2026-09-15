<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

enum LoginOutcome: string
{
    case Success = 'success';
    case MfaRequired = 'mfa_required';
    case InvalidCredentials = 'invalid_credentials';
    case AccountUnavailable = 'account_unavailable';
    case RateLimited = 'rate_limited';
}
