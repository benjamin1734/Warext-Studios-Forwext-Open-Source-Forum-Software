<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Content;

use Forwext\Core\Domain\Entity\EntityId;

interface UserForumContentReader
{
    /** @return list<UserForumContentItem> */
    public function threads(EntityId $viewerUserId, EntityId $targetUserId, int $limit = 20, int $offset = 0): array;

    /** @return list<UserForumContentItem> */
    public function posts(EntityId $viewerUserId, EntityId $targetUserId, int $limit = 20, int $offset = 0): array;
}
