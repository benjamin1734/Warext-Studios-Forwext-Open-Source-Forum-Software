<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

use Closure;
use Forwext\Core\Extension\ExtensionOwner;
use InvalidArgumentException;

final readonly class ServiceDecorator
{
    /** @param Closure(mixed,Container):mixed $factory */
    public function __construct(
        public ExtensionOwner $owner,
        public Closure $factory,
        public int $priority,
        public int $sequence,
    ) {
        if ($this->priority < -100000 || $this->priority > 100000 || $this->sequence < 0) {
            throw new InvalidArgumentException('Service decorator ordering metadata is invalid.');
        }
    }
}
