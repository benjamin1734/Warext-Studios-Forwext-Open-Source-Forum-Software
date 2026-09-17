<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use InvalidArgumentException;

final readonly class ModerationWorkspaceService
{
    /** @param list<ModerationWorkspaceSource> $sources */
    public function __construct(
        private PermissionGate $gate,
        private array $sources,
    ) {
        foreach ($this->sources as $source) {
            if (!$source instanceof ModerationWorkspaceSource) {
                throw new InvalidArgumentException('Moderation workspace source list is invalid.');
            }
        }
    }

    public function snapshot(int $limitPerSection = 20): ModerationWorkspaceSnapshot
    {
        if ($limitPerSection < 1 || $limitPerSection > 50) {
            throw new InvalidArgumentException('Moderation workspace section limit must be between 1 and 50.');
        }

        $this->gate->require(PermissionKey::fromString('moderation.access'));

        $counts = [];
        $items = [];
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $counts[$section->value] = 0;
            $items[$section->value] = [];
        }

        foreach ($this->sources as $source) {
            $section = $source->section();
            $count = $source->count();
            if ($count < 0) {
                throw new InvalidArgumentException('Moderation workspace source returned a negative count.');
            }
            $counts[$section->value] += $count;
            $items[$section->value] = array_merge(
                $items[$section->value],
                $source->latest($limitPerSection),
            );
        }

        foreach (ModerationWorkspaceSection::cases() as $section) {
            usort(
                $items[$section->value],
                static fn (ModerationWorkspaceItem $left, ModerationWorkspaceItem $right): int =>
                    [$right->updatedAt->format('U.u'), $right->sourceId]
                    <=> [$left->updatedAt->format('U.u'), $left->sourceId],
            );
            $items[$section->value] = array_slice($items[$section->value], 0, $limitPerSection);
        }

        return new ModerationWorkspaceSnapshot($counts, $items);
    }
}
