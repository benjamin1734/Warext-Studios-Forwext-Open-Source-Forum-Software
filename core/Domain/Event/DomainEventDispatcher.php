<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

use Closure;
use Forwext\Core\Extension\ExtensionOwner;
use InvalidArgumentException;

final class DomainEventDispatcher
{
    /** @var array<string,list<DomainEventListenerRegistration>> */
    private array $namedListeners = [];

    /** @var array<class-string<DomainEvent>,list<DomainEventListenerRegistration>> */
    private array $typedListeners = [];

    private int $sequence = 0;

    /**
     * @param Closure(DomainEvent):void $listener
     */
    public function listen(
        string $eventName,
        Closure $listener,
        int $priority = 0,
        ?ExtensionOwner $owner = null,
    ): void {
        $eventName = trim($eventName);

        if ($eventName === '' || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $eventName) !== 1) {
            throw new InvalidArgumentException('Domain event name contains unsupported characters or length.');
        }

        $this->namedListeners[$eventName] ??= [];
        $this->namedListeners[$eventName][] = new DomainEventListenerRegistration(
            $owner ?? ExtensionOwner::core(),
            $listener,
            $priority,
            $this->sequence++,
            eventName:$eventName,
        );
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     * @param Closure(DomainEvent):void $listener
     */
    public function listenTyped(
        string $eventClass,
        Closure $listener,
        int $priority = 0,
        ?ExtensionOwner $owner = null,
    ): void {
        if (!is_a($eventClass, DomainEvent::class, true)) {
            throw new InvalidArgumentException('Typed domain event listener must target a DomainEvent class.');
        }

        $this->typedListeners[$eventClass] ??= [];
        $this->typedListeners[$eventClass][] = new DomainEventListenerRegistration(
            $owner ?? ExtensionOwner::core(),
            $listener,
            $priority,
            $this->sequence++,
            eventClass:$eventClass,
        );
    }

    public function dispatch(DomainEvent $event): void
    {
        $listeners = $this->namedListeners[$event->eventName()] ?? [];
        foreach ($this->typedListeners as $eventClass=>$typed) {
            if ($event instanceof $eventClass) {
                array_push($listeners, ...$typed);
            }
        }

        usort(
            $listeners,
            static fn (DomainEventListenerRegistration $left, DomainEventListenerRegistration $right): int =>
                [$right->priority, $left->sequence] <=> [$left->priority, $right->sequence],
        );

        foreach ($listeners as $registration) {
            ($registration->listener)($event);
        }
    }

    /** @param iterable<DomainEvent> $events */
    public function dispatchAll(iterable $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    /** @return list<DomainEventListenerDiagnostic> */
    public function listenerDiagnostics(): array
    {
        $registrations = [];
        foreach ($this->namedListeners as $listeners) {
            array_push($registrations, ...$listeners);
        }
        foreach ($this->typedListeners as $listeners) {
            array_push($registrations, ...$listeners);
        }
        usort(
            $registrations,
            static fn (DomainEventListenerRegistration $left, DomainEventListenerRegistration $right): int =>
                $left->sequence <=> $right->sequence,
        );

        return array_map(
            static fn (DomainEventListenerRegistration $registration): DomainEventListenerDiagnostic =>
                new DomainEventListenerDiagnostic(
                    $registration->owner->value(),
                    $registration->eventName,
                    $registration->eventClass,
                    $registration->priority,
                ),
            $registrations,
        );
    }
}
