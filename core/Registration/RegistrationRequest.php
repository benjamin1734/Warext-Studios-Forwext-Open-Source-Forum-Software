<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class RegistrationRequest
{
    /** @param array<string, string> $acceptedLegalVersions */
    public function __construct(
        public string $username,
        public string $email,
        public string $locale,
        public string $timezone,
        public string $clientIp,
        public ?string $captchaToken = null,
        public ?string $inviteCode = null,
        public array $acceptedLegalVersions = [],
        #[SensitiveParameter] public ?string $password = null,
    ) {
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Registration client IP is invalid.');
        }
        foreach ($acceptedLegalVersions as $type => $version) {
            if (!is_string($type) || !is_string($version)) {
                throw new InvalidArgumentException('Registration legal acceptance map is invalid.');
            }
        }
    }
}
