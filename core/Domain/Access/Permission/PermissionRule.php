<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PermissionRule
{
    public function __construct(
        private PermissionSubjectType $subjectType,
        private EntityId $subjectId,
        private PermissionEffect $effect,
        private ?EntityId $nodeId = null,
        private ?int $numericLimit = null,
    ) {
        if ($this->numericLimit !== null && $this->numericLimit < 0) {
            throw new InvalidArgumentException('Numeric permission limit cannot be negative.');
        }
    }

    public function subjectType(): PermissionSubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): EntityId
    {
        return $this->subjectId;
    }

    public function effect(): PermissionEffect
    {
        return $this->effect;
    }

    public function nodeId(): ?EntityId
    {
        return $this->nodeId;
    }

    public function numericLimit(): ?int
    {
        return $this->numericLimit;
    }

    public function isNodeScoped(): bool
    {
        return $this->nodeId !== null;
    }

    public function isValidFor(PermissionDefinition $definition): bool
    {
        if ($this->effect !== PermissionEffect::Allow) {
            return $this->numericLimit === null;
        }

        return match ($definition->valueType()) {
            PermissionValueType::Flag => $this->numericLimit === null,
            PermissionValueType::Numeric => $this->numericLimit !== null,
        };
    }
}
