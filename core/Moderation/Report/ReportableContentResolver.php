<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;

interface ReportableContentResolver
{
    public function targetType(): string;

    public function resolve(EntityId $viewerUserId, EntityId $targetId): ?ReportableContent;
}
