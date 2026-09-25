<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendNamespace;
use Forwext\Core\Extension\ExtensionOwner;
use InvalidArgumentException;

final class QueueJobHandlerRegistry
{
    /** @var array<string,RegisteredQueueJobHandler> */
    private array $handlers = [];

    public function registerCore(QueueJobHandler $handler): void
    {
        $this->register(ExtensionOwner::core(), $handler);
    }

    public function registerAddon(AddonId $addonId, QueueJobHandler $handler): void
    {
        AddonBackendNamespace::fromAddonId($addonId)->assertOwned($handler->jobType(), 'Add-on job type');
        $this->register(ExtensionOwner::addon($addonId->value()), $handler);
    }

    public function register(ExtensionOwner $owner, QueueJobHandler $handler): void
    {
        $type = trim($handler->jobType());
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Queue job handler type is invalid.');
        }
        if (isset($this->handlers[$type])) {
            throw new InvalidArgumentException('Queue job handler type is already registered: ' . $type);
        }

        $this->handlers[$type] = new RegisteredQueueJobHandler($owner, $handler);
    }

    public function require(string $type): RegisteredQueueJobHandler
    {
        return $this->handlers[$type]
            ?? throw new InvalidArgumentException('Unknown queue job handler type: ' . $type);
    }

    /** @return list<RegisteredQueueJobHandler> */
    public function all(): array
    {
        $handlers = $this->handlers;
        ksort($handlers, SORT_STRING);

        return array_values($handlers);
    }
}
