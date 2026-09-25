<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

use Closure;
use Forwext\Core\Extension\ExtensionOwner;
use InvalidArgumentException;

final readonly class DomainEventListenerRegistration
{
    /**
     * @param Closure(DomainEvent):void $listener
     * @param class-string<DomainEvent>|null $eventClass
     */
    public function __construct(
        public ExtensionOwner $owner,
        public Closure $listener,
        public int $priority,
        public int $sequence,
        public ?string $eventName = null,
        public ?string $eventClass = null,
    ) {
        if (($this->eventName === null) === ($this->eventClass === null)) {
            throw new InvalidArgumentException('Event listener must select exactly one event name or event class.');
        }
        if ($this->priority < -100000 || $this->priority > 100000 || $this->sequence < 0) {
            throw new InvalidArgumentException('Event listener ordering metadata is invalid.');
        }
    }
}
