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
        public ?string $clientUserAgent = null,
        public ?string $referralCode = null,
    ) {
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Registration client IP is invalid.');
        }
        if ($this->clientUserAgent !== null
            && (trim($this->clientUserAgent) === '' || strlen($this->clientUserAgent) > 1024
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->clientUserAgent) === 1)
        ) {
            throw new InvalidArgumentException('Registration client user agent is invalid.');
        }
        foreach ($acceptedLegalVersions as $type => $version) {
            if (!is_string($type) || !is_string($version)) {
                throw new InvalidArgumentException('Registration legal acceptance map is invalid.');
            }
        }
    }
}
