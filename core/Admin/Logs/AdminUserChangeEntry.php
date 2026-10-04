<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Logs;

use DateTimeImmutable;

final readonly class AdminUserChangeEntry
{
    /** @param list<string> $changedFields */
    public function __construct(
        public string $userId,
        public string $username,
        public string $eventType,
        public array $changedFields,
        public DateTimeImmutable $occurredAt,
        public ?string $actorUserId,
        public ?string $actorUsername,
        public ?string $fromStatus,
        public ?string $toStatus,
        public ?string $reasonCode,
    ) {
    }
}
