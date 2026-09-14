<?php

declare(strict_types=1);

namespace Forwext\Core\Auth;

final class AuthenticationRejectedException extends AuthException
{
    public function __construct()
    {
        parent::__construct('Authentication failed.');
    }
}
