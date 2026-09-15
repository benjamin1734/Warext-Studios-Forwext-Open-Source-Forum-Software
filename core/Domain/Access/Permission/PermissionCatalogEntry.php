<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use InvalidArgumentException;

final readonly class PermissionCatalogEntry
{
    private PermissionKey $key;
    private PermissionValueType $valueType;
    private string $description;

    public function __construct(
        PermissionKey $key,
        PermissionValueType $valueType,
        string $description,
    ) {
        $description = trim($description);
        if ($description === '' || strlen($description) > 255) {
            throw new InvalidArgumentException('Permission description must be 1-255 bytes.');
        }

        $this->key = $key;
        $this->valueType = $valueType;
        $this->description = $description;
    }

    public function key(): PermissionKey
    {
        return $this->key;
    }

    public function namespace(): PermissionNamespace
    {
        return PermissionNamespace::fromKey($this->key);
    }

    public function valueType(): PermissionValueType
    {
        return $this->valueType;
    }

    public function description(): string
    {
        return $this->description;
    }
}
