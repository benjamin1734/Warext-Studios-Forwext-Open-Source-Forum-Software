<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use InvalidArgumentException;

final class NotificationRegistry
{
    /** @var array<string, NotificationDefinition> */
    private array $definitions = [];

    public function register(NotificationDefinition $definition): void
    {
        if (isset($this->definitions[$definition->typeKey])) {
            throw new InvalidArgumentException(sprintf('Notification type "%s" is already registered.', $definition->typeKey));
        }
        $this->definitions[$definition->typeKey] = $definition;
    }

    public function has(string $typeKey): bool
    {
        return isset($this->definitions[$typeKey]);
    }

    public function require(string $typeKey): NotificationDefinition
    {
        return $this->definitions[$typeKey]
            ?? throw new InvalidArgumentException(sprintf('Unknown notification type "%s".', $typeKey));
    }

    /** @return list<NotificationDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
