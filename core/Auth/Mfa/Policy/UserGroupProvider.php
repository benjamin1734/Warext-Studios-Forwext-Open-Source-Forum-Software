<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Policy;

use Forwext\Core\Domain\Entity\EntityId;

interface UserGroupProvider
{
    /** @return list<string> */
    public function groupsFor(EntityId $userId): array;
}
