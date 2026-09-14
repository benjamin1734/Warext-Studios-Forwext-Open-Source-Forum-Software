<?php

declare(strict_types=1);

namespace Forwext\Core\Container\Exception;

final class OverrideNotAllowedException extends ContainerException
{
    public static function forId(string $id): self
    {
        return new self(sprintf(
            'Service override for "%s" is disabled. Use a testing container when overrides are required.',
            $id,
        ));
    }
}
