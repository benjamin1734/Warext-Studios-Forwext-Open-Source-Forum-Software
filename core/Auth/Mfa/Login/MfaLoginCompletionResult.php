<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Login;

use Forwext\Core\Auth\Login\LoginResult;

final readonly class MfaLoginCompletionResult
{
    public function __construct(public LoginResult $login, public ?string $trustedDeviceToken)
    {
    }
}
