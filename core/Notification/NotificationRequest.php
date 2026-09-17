<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class NotificationRequest
{
    /**
     * @param array<string, scalar|null> $variables
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public EntityId $recipientUserId,
        public string $typeKey,
        public array $variables = [],
        public ?string $groupKey = null,
        public ?string $dedupeKey = null,
        public ?string $actionPath = null,
        public array $payload = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $typeKey) !== 1) {
            throw new InvalidArgumentException('Notification type key is invalid.');
        }
        self::assertOptionalKey($groupKey, 191, 'group');
        self::assertOptionalKey($dedupeKey, 191, 'dedupe');
        if ($actionPath !== null) {
            if (
                $actionPath === ''
                || strlen($actionPath) > 1000
                || !str_starts_with($actionPath, '/')
                || str_starts_with($actionPath, '//')
                || preg_match('/[\x00-\x1F\x7F]/', $actionPath) === 1
            ) {
                throw new InvalidArgumentException('Notification action path must be a safe same-origin path.');
            }
        }
        foreach ($variables as $key => $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1 || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Notification template variables must use valid names and scalar values.');
            }
        }
        foreach ($payload as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1 || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Notification payload must use valid keys and scalar values.');
            }
        }
    }

    private static function assertOptionalKey(?string $value, int $maxLength, string $label): void
    {
        if ($value === null) return;
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Notification %s key is invalid.', $label));
        }
    }
}
