<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use Forwext\Core\Domain\Entity\EntityId;

interface UserAuthenticationAvailability
{
    public function allows(EntityId $userId): bool;
}
