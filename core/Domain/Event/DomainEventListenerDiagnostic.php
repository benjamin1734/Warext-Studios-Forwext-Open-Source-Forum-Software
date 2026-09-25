<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

final readonly class DomainEventListenerDiagnostic
{
    /** @param class-string<DomainEvent>|null $eventClass */
    public function __construct(
        public string $owner,
        public ?string $eventName,
        public ?string $eventClass,
        public int $priority,
    ) {
    }
}
