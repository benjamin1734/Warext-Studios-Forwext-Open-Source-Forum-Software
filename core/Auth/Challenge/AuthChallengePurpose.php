<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Challenge;

enum AuthChallengePurpose: string
{
    case PasswordReset = 'password_reset';
    case PasswordConfirmation = 'password_confirmation';
}
