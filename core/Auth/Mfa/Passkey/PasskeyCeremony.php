<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

final readonly class PasskeyCeremony
{
    public function __construct(public string $token, public string $optionsJson)
    {
    }
}
