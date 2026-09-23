<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class LayoutPlacement
{
    public function __construct(
        public EntityId $placementId,
        public string $widgetKey,
        public string $slotKey,
        public int $order,
        public bool $enabled,
        public LayoutPlacementCondition $condition,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->placementId->value()) !== 1) {
            throw new InvalidArgumentException('Layout placement id must be a 128-bit lowercase hexadecimal identifier.');
        }

        foreach ([$this->widgetKey, $this->slotKey] as $key) {
            if (
                strlen($key) < 3
                || strlen($key) > 96
                || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $key) !== 1
            ) {
                throw new InvalidArgumentException('Layout placement registry key is invalid.');
            }
        }

        if ($this->order < 0 || $this->order > 65535) {
            throw new InvalidArgumentException('Layout placement order must be between 0 and 65535.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->placementId->value(),
            'widget' => $this->widgetKey,
            'slot' => $this->slotKey,
            'order' => $this->order,
            'enabled' => $this->enabled,
            'condition' => $this->condition->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $widget = $data['widget'] ?? null;
        $slot = $data['slot'] ?? null;
        $order = $data['order'] ?? null;
        $enabled = $data['enabled'] ?? null;
        $condition = $data['condition'] ?? [];

        if (
            !is_string($id)
            || !is_string($widget)
            || !is_string($slot)
            || !is_int($order)
            || !is_bool($enabled)
            || !is_array($condition)
        ) {
            throw new InvalidArgumentException('Layout placement shape is invalid.');
        }

        return new self(
            EntityId::fromString($id),
            $widget,
            $slot,
            $order,
            $enabled,
            LayoutPlacementCondition::fromArray($condition),
        );
    }
}
