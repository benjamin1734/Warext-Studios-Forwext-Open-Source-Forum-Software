<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

final readonly class PermissionDefinition
{
    public function __construct(
        private PermissionKey $key,
        private PermissionValueType $valueType,
    ) {
    }

    public function key(): PermissionKey
    {
        return $this->key;
    }

    public function valueType(): PermissionValueType
    {
        return $this->valueType;
    }
}
