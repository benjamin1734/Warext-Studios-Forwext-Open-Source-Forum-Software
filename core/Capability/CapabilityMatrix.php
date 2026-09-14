<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

use InvalidArgumentException;

final readonly class CapabilityMatrix
{
    /** @var array<string, CapabilityEntry> */
    private array $entries;

    /**
     * @param iterable<CapabilityEntry> $entries
     * @param array<string, string|int|float|bool|null|list<string>> $metadata
     */
    public function __construct(iterable $entries, public array $metadata = [])
    {
        $normalized = [];
        foreach ($entries as $entry) {
            if (isset($normalized[$entry->name])) {
                throw new InvalidArgumentException(sprintf('Duplicate capability "%s".', $entry->name));
            }
            $normalized[$entry->name] = $entry;
        }
        $this->entries = $normalized;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function available(string $name): bool
    {
        return $this->entries[$name]->available ?? false;
    }

    public function entry(string $name): ?CapabilityEntry
    {
        return $this->entries[$name] ?? null;
    }

    /** @return list<CapabilityEntry> */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /** @return list<CapabilityEntry> */
    public function missingMinimum(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (CapabilityEntry $entry): bool => $entry->requiredForMinimumProfile && !$entry->available,
        ));
    }
}
