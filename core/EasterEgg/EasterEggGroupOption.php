<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class EasterEggGroupOption
{
    public function __construct(
        public EntityId $groupId,
        public string $name,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->groupId->value()) !== 1) {
            throw new InvalidArgumentException('Easter egg group option id is invalid.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 100 || preg_match('//u', $this->name) !== 1) {
            throw new InvalidArgumentException('Easter egg group option name is invalid.');
        }
    }
}
