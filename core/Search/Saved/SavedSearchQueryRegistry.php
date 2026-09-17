<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Saved;

use InvalidArgumentException;

final class SavedSearchQueryRegistry
{
    /** @var array<string,SavedSearchQueryExtension> */
    private array $extensions = [];

    /** @param iterable<SavedSearchQueryExtension> $extensions */
    public function __construct(iterable $extensions = [])
    {
        foreach ($extensions as $extension) $this->register($extension);
    }

    public function register(SavedSearchQueryExtension $extension): void
    {
        $key = $extension->key();
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Saved search extension key is invalid.');
        }
        if (isset($this->extensions[$key])) {
            throw new InvalidArgumentException(sprintf('Saved search extension "%s" is already registered.', $key));
        }
        $this->extensions[$key] = $extension;
    }

    public function find(string $key): ?SavedSearchQueryExtension
    {
        return $this->extensions[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->extensions);
    }
}
