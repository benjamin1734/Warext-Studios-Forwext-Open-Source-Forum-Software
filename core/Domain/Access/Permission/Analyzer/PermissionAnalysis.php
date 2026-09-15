<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Analyzer;

use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PermissionAnalysis
{
    /** @param list<PermissionAnalysisLayer> $layers */
    public function __construct(
        private PermissionKey $permissionKey,
        private ?EntityId $nodeId,
        private bool $allowed,
        private ?int $numericLimit,
        private string $reasonCode,
        private string $summary,
        private array $layers,
    ) {
    }

    public function permissionKey(): PermissionKey
    {
        return $this->permissionKey;
    }

    public function nodeId(): ?EntityId
    {
        return $this->nodeId;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function numericLimit(): ?int
    {
        return $this->numericLimit;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return list<PermissionAnalysisLayer> */
    public function layers(): array
    {
        return $this->layers;
    }
}
