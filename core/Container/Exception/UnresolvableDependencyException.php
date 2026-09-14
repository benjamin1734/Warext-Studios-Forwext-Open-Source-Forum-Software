<?php

declare(strict_types=1);

namespace Forwext\Core\Container\Exception;

use ReflectionParameter;

final class UnresolvableDependencyException extends ContainerException
{
    public static function forParameter(string $class, ReflectionParameter $parameter): self
    {
        return new self(sprintf(
            'Cannot autowire parameter $%s of %s::__construct(); bind it explicitly or provide a default value.',
            $parameter->getName(),
            $class,
        ));
    }
}
