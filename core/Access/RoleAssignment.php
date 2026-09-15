<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class RoleAssignment
{
    public DateTimeImmutable $assignedAt;

    public function __construct(
        public RoleKey $role,
        public RoleAssignmentSource $source,
        DateTimeImmutable $assignedAt,
        public ?EntityId $assignedBy = null,
    ) {
        $this->assignedAt = $assignedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
