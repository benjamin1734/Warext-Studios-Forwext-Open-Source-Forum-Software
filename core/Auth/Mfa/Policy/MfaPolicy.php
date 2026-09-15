<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Policy;

final readonly class MfaPolicy
{
    public function __construct(public bool $loginRequired = false, public bool $sensitiveActionRequired = true, public bool $trustedDeviceMayBypassLogin = true)
    {
    }

    public function mergeStrictest(self $other): self
    {
        return new self(
            $this->loginRequired || $other->loginRequired,
            $this->sensitiveActionRequired || $other->sensitiveActionRequired,
            $this->trustedDeviceMayBypassLogin && $other->trustedDeviceMayBypassLogin,
        );
    }
}
