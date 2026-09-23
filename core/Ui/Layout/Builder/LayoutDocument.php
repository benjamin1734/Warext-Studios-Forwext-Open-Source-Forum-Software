<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use InvalidArgumentException;

final readonly class LayoutDocument
{
    public const VERSION = 1;

    /** @var list<LayoutPlacement> */
    public array $placements;

    /** @param list<LayoutPlacement> $placements */
    public function __construct(array $placements)
    {
        if (count($placements) > 200) {
            throw new InvalidArgumentException('A layout cannot contain more than 200 widget placements.');
        }

        $ids = [];
        foreach ($placements as $placement) {
            if (!$placement instanceof LayoutPlacement) {
                throw new InvalidArgumentException('Layout contains an invalid placement.');
            }
            $id = $placement->placementId->value();
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Layout placement ids must be unique.');
            }
            $ids[$id] = true;
        }

        usort(
            $placements,
            static fn (LayoutPlacement $left, LayoutPlacement $right): int =>
                [$left->slotKey, $left->order, $left->placementId->value()]
                <=> [$right->slotKey, $right->order, $right->placementId->value()],
        );
        $this->placements = array_values($placements);
    }

    /** @return array{version:int,placements:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'placements' => array_map(
                static fn (LayoutPlacement $placement): array => $placement->toArray(),
                $this->placements,
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported layout document version.');
        }

        $placements = $data['placements'] ?? null;
        if (!is_array($placements) || !array_is_list($placements)) {
            throw new InvalidArgumentException('Layout placements must be a list.');
        }

        $typed = [];
        foreach ($placements as $placement) {
            if (!is_array($placement)) {
                throw new InvalidArgumentException('Layout placement must be an object.');
            }
            $typed[] = LayoutPlacement::fromArray($placement);
        }

        return new self($typed);
    }
}
