<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class Notification
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public EntityId $id,
        public EntityId $recipientUserId,
        public string $typeKey,
        public string $categoryKey,
        public string $title,
        public string $body,
        public ?string $actionPath,
        public array $payload,
        public bool $inAppVisible,
        public int $occurrences,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $readAt = null,
    ) {
    }
}
