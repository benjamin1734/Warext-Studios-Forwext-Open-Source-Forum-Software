<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use DomainException;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PermissionTemplateApplier
{
    public function __construct(
        private PermissionTemplateRepository $templates,
        private PermissionTemplateRuleWriter $writer,
    ) {
    }

    public function apply(
        PermissionTemplateKey $templateKey,
        PermissionSubjectType $subjectType,
        EntityId $subjectId,
    ): int {
        $template = $this->templates->find($templateKey);
        if ($template === null) {
            throw new DomainException('Permission template was not found.');
        }

        return $this->writer->apply($template, $subjectType, $subjectId);
    }
}
