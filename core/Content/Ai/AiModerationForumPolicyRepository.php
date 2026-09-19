<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Domain\Entity\EntityId;

interface AiModerationForumPolicyRepository
{
    public function find(EntityId $forumNodeId): ?AiModerationForumPolicy;
}
