<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use InvalidArgumentException;

final class ThreadTypeRegistry
{
    /** @var array<string, ThreadTypeDefinition> */
    private array $definitions = [];

    /** @param iterable<ThreadTypeDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public static function withCoreDefaults(): self
    {
        return new self([
            new ThreadTypeDefinition(
                ThreadTypeKey::fromString('discussion'),
                'Discussion',
                true,
                true,
            ),
        ]);
    }

    public function register(ThreadTypeDefinition $definition): void
    {
        $key = $definition->key()->value();
        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException(sprintf('Thread type "%s" is already registered.', $key));
        }

        $this->definitions[$key] = $definition;
    }

    public function find(ThreadTypeKey $key): ?ThreadTypeDefinition
    {
        return $this->definitions[$key->value()] ?? null;
    }

    public function require(ThreadTypeKey $key): ThreadTypeDefinition
    {
        return $this->find($key)
            ?? throw new InvalidArgumentException(sprintf('Unknown thread type "%s".', $key->value()));
    }

    /** @return list<ThreadTypeDefinition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);
        return array_values($definitions);
    }
}
