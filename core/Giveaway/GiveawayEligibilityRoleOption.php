<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class GiveawayEligibilityRoleOption
{
    public function __construct(
        public EntityId $roleId,
        public string $name,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->roleId->value()) !== 1) {
            throw new InvalidArgumentException('Giveaway role option id is invalid.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 100) {
            throw new InvalidArgumentException('Giveaway role option name is invalid.');
        }
    }
}
