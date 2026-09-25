<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

final readonly class WebhookTransportResult
{
    public function __construct(
        public bool $successful,
        public bool $retryable,
        public ?int $httpStatus = null,
        public ?string $errorCode = null,
    ) {
    }

    public static function success(int $status): self
    {
        return new self(true, false, $status);
    }

    public static function failure(string $code, bool $retryable, ?int $status = null): self
    {
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $code) !== 1) {
            throw new WebhookException('Webhook transport error code is invalid.');
        }
        return new self(false, $retryable, $status, $code);
    }
}
