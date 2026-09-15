<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Analyzer;

final readonly class PermissionAnalysisLayer
{
    /** @param list<PermissionAnalysisStep> $steps */
    public function __construct(
        private string $key,
        private string $label,
        private PermissionAnalysisLayerState $state,
        private string $explanation,
        private array $steps,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function state(): PermissionAnalysisLayerState
    {
        return $this->state;
    }

    public function explanation(): string
    {
        return $this->explanation;
    }

    /** @return list<PermissionAnalysisStep> */
    public function steps(): array
    {
        return $this->steps;
    }
}
