<?php

declare(strict_types=1);

namespace Forwext\Core\Extension;

use Forwext\Core\Container\Container;
use Forwext\Core\Domain\Event\DomainEventDispatcher;

final readonly class ExtensionGraphDiagnostics
{
    public static function snapshot(
        Container $container,
        DomainEventDispatcher $events,
    ): ExtensionGraphSnapshot {
        return new ExtensionGraphSnapshot(
            $container->extensionDiagnostics(),
            $events->listenerDiagnostics(),
        );
    }
}
