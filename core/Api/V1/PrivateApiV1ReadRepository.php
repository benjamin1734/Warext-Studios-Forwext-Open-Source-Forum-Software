<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use Forwext\Core\Domain\Entity\EntityId;

interface PrivateApiV1ReadRepository
{
    public function conversations(EntityId $userId, int $page, int $perPage): ApiV1Page;

    public function notifications(EntityId $userId, int $page, int $perPage): ApiV1Page;

    public function supportTickets(EntityId $userId, int $page, int $perPage): ApiV1Page;
}
