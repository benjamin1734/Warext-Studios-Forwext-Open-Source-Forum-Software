<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;

interface SupportContextResolver
{
    public function type(): SupportContextType;

    public function resolve(EntityId $actorUserId, EntityId $targetId): SupportContextLink;
}
