<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use InvalidArgumentException;

final readonly class PermissionTemplateRule
{
    public function __construct(
        private PermissionDefinition $definition,
        private PermissionEffect $effect,
        private ?int $numericLimit = null,
    ) {
        if ($this->numericLimit !== null && $this->numericLimit < 0) {
            throw new InvalidArgumentException('Permission template numeric limit cannot be negative.');
        }

        $valid = $this->effect === PermissionEffect::Allow
            ? match ($this->definition->valueType()) {
                PermissionValueType::Flag => $this->numericLimit === null,
                PermissionValueType::Numeric => $this->numericLimit !== null,
            }
            : $this->numericLimit === null;

        if (!$valid) {
            throw new InvalidArgumentException('Permission template rule does not match permission value type/effect semantics.');
        }
    }

    public function definition(): PermissionDefinition
    {
        return $this->definition;
    }

    public function effect(): PermissionEffect
    {
        return $this->effect;
    }

    public function numericLimit(): ?int
    {
        return $this->numericLimit;
    }
}
