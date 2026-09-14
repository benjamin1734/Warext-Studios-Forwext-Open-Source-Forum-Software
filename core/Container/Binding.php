<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

use Closure;

final readonly class Binding
{
    public function __construct(
        public Closure|string $concrete,
        public ServiceLifetime $lifetime,
    ) {
    }
}
