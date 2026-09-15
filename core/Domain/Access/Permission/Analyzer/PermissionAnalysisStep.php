<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Analyzer;

use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PermissionAnalysisStep
{
    public function __construct(
        private string $tier,
        private PermissionSubjectType $subjectType,
        private EntityId $subjectId,
        private PermissionEffect $effect,
        private ?EntityId $nodeId,
        private ?int $numericLimit,
        private string $outcome,
        private string $explanation,
    ) {
    }

    public function tier(): string
    {
        return $this->tier;
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

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function explanation(): string
    {
        return $this->explanation;
    }
}
