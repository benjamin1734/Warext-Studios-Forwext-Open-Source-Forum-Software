<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

final readonly class NotificationRealtimeBatch
{
    /** @param list<NotificationRealtimeItem> $items */
    public function __construct(public int $cursor, public array $items)
    {
        if ($cursor < 0) {
            throw new NotificationRealtimeException('Notification realtime cursor cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cursor' => $this->cursor,
            'items' => array_map(
                static fn (NotificationRealtimeItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
