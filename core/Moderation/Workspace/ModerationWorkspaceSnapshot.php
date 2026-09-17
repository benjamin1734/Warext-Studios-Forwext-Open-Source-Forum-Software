<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use InvalidArgumentException;

final readonly class ModerationWorkspaceSnapshot
{
    /**
     * @param array<string, int> $counts
     * @param array<string, list<ModerationWorkspaceItem>> $items
     */
    public function __construct(
        public array $counts,
        public array $items,
    ) {
        foreach (ModerationWorkspaceSection::cases() as $section) {
            if (!array_key_exists($section->value, $this->counts)
                || !array_key_exists($section->value, $this->items)
                || $this->counts[$section->value] < 0
            ) {
                throw new InvalidArgumentException('Moderation workspace snapshot is incomplete.');
            }
        }
    }

    public function count(ModerationWorkspaceSection $section): int
    {
        return $this->counts[$section->value];
    }

    /** @return list<ModerationWorkspaceItem> */
    public function itemsFor(ModerationWorkspaceSection $section): array
    {
        return $this->items[$section->value];
    }
}
