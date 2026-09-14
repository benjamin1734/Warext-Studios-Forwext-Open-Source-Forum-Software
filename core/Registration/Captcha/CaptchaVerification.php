<?php

declare(strict_types=1);

namespace Forwext\Core\Registration\Captcha;

final readonly class CaptchaVerification
{
    /** @param list<string> $errorCodes */
    public function __construct(
        public bool $success,
        public array $errorCodes = [],
        public ?string $hostname = null,
        public ?string $action = null,
    ) {
    }
}
