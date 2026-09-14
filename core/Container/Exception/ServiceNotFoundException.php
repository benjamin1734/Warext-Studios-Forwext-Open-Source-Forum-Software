<?php

declare(strict_types=1);

namespace Forwext\Core\Container\Exception;

final class ServiceNotFoundException extends ContainerException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Service "%s" is not bound and cannot be autowired.', $id));
    }
}
