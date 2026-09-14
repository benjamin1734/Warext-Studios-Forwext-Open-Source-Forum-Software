<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

use Closure;
use InvalidArgumentException;

final class DomainEventDispatcher
{
    /** @var array<string, list<Closure(DomainEvent): void>> */
    private array $listeners = [];

    /** @param Closure(DomainEvent): void $listener */
    public function listen(string $eventName, Closure $listener): void
    {
        $eventName = trim($eventName);

        if ($eventName === '' || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $eventName) !== 1) {
            throw new InvalidArgumentException('Domain event name contains unsupported characters or length.');
        }

        $this->listeners[$eventName] ??= [];
        $this->listeners[$eventName][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->listeners[$event->eventName()] ?? [] as $listener) {
            $listener($event);
        }
    }

    /** @param iterable<DomainEvent> $events */
    public function dispatchAll(iterable $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }
}
