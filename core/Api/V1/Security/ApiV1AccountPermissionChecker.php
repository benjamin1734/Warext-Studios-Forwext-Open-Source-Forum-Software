<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Domain\Entity\EntityId;

interface ApiV1AccountPermissionChecker
{
    public function allows(EntityId $userId, ApiV1Scope $scope): bool;
}
