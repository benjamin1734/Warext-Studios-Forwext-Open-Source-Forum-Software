<?php

declare(strict_types=1);

namespace Forwext\Core\Registration\Captcha;

interface TurnstileTransport
{
    /** @param array<string, string> $fields */
    public function postForm(string $url, array $fields, int $timeoutSeconds = 5): string;
}
