<?php

declare(strict_types=1);

namespace Forwext\Core\Registration\Captcha;

interface CaptchaVerifier
{
    public function verify(string $token, string $clientIp): CaptchaVerification;
}
