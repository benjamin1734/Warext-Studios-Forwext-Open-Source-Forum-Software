<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class RealtimeNotificationSnapshot
{
    public function __construct(
        public EntityId $notificationId,
        public string $typeKey,
        public string $categoryKey,
        public string $title,
        public string $body,
        public ?string $actionPath,
        public int $occurrences,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $readAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'notification_id' => $this->notificationId->value(),
            'type_key' => $this->typeKey,
            'category_key' => $this->categoryKey,
            'title' => $this->title,
            'body' => $this->body,
            'action_path' => $this->actionPath,
            'occurrences' => $this->occurrences,
            'updated_at_utc' => $this->updatedAt->format('Y-m-d\TH:i:s.u\Z'),
            'read' => $this->readAt !== null,
        ];
    }
}
