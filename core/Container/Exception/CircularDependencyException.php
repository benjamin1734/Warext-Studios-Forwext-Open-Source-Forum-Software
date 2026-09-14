<?php

declare(strict_types=1);

namespace Forwext\Core\Container\Exception;

final class CircularDependencyException extends ContainerException
{
    /** @param list<string> $path */
    public static function fromPath(array $path): self
    {
        return new self('Circular dependency detected: ' . implode(' -> ', $path));
    }
}
