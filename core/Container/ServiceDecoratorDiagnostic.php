<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

final readonly class ServiceDecoratorDiagnostic
{
    public function __construct(
        public string $owner,
        public int $priority,
    ) {
    }
}
