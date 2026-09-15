<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Login;

use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Mfa\MfaMethod;

final class SecondFactorRequiredException extends AuthException
{
    /** @param list<MfaMethod> $methods */
    public function __construct(
        public readonly string $challengeToken,
        public readonly array $methods,
        public readonly bool $enrollmentRequired,
    ) {
        parent::__construct('Additional authentication required.');
    }
}
