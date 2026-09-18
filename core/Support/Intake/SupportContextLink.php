<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class SupportContextLink
{
    public function __construct(
        public SupportContextType $type,
        public EntityId $targetId,
        public string $labelSnapshot,
    ) {
        if (trim($this->labelSnapshot) === '' || strlen($this->labelSnapshot) > 255) {
            throw new InvalidArgumentException('Support context label must contain 1-255 UTF-8 bytes.');
        }
    }
}
