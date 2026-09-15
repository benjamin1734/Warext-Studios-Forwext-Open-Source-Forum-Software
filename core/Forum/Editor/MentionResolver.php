<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;

interface MentionResolver
{
    public function resolve(EntityId $userId): ?MentionTarget;
}
