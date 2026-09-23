<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout;

use InvalidArgumentException;

final readonly class UiSlotDefinition
{
    public function __construct(
        public string $key,
        public LayoutRegion $region,
        public int $order,
        public string $description,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('UI slot key is invalid.');
        }

        if ($this->order < -10000 || $this->order > 10000) {
            throw new InvalidArgumentException('UI slot order is outside supported bounds.');
        }

        $description = trim($this->description);
        if ($description === '' || strlen($description) > 240) {
            throw new InvalidArgumentException('UI slot description must contain 1..240 bytes.');
        }
    }
}
