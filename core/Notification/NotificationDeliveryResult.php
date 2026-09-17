<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use InvalidArgumentException;

final readonly class NotificationDeliveryResult
{
    private function __construct(
        public bool $successful,
        public bool $retryable,
        public ?string $errorCode,
    ) {
        if ($errorCode !== null && preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $errorCode) !== 1) {
            throw new InvalidArgumentException('Notification delivery error code is invalid.');
        }
    }

    public static function sent(): self
    {
        return new self(true, false, null);
    }

    public static function failed(string $errorCode, bool $retryable = true): self
    {
        return new self(false, $retryable, $errorCode);
    }
}
