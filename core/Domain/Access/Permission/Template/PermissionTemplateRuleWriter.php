<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Entity\EntityId;

interface PermissionTemplateRuleWriter
{
    public function apply(
        PermissionTemplate $template,
        PermissionSubjectType $subjectType,
        EntityId $subjectId,
    ): int;
}
