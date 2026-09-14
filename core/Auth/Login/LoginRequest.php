<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use SensitiveParameter;

final readonly class LoginRequest
{
    public function __construct(
        public string $identifier,
        #[SensitiveParameter] public string $password,
        public string $clientIp,
        public string $userAgent,
        public ?string $deviceId = null,
        public bool $rememberMe = false,
    ) {
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false || $identifier === '' || strlen($identifier) > 512) {
            throw new \InvalidArgumentException('Login request identity/network input is invalid.');
        }
        if ($userAgent === '' || strlen($userAgent) > 2048 || str_contains($userAgent, "\0")) {
            throw new \InvalidArgumentException('Login request user-agent is invalid.');
        }
    }
}
