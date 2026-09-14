<?php

declare(strict_types=1);

namespace Forwext\Core\Config;

final readonly class ConfigRepository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function has(string $key): bool
    {
        $segments = $this->segments($key);
        $current = $this->items;

        foreach ($segments as $index => $segment) {
            if (!array_key_exists($segment, $current)) {
                return false;
            }

            if ($index === array_key_last($segments)) {
                return true;
            }

            $next = $current[$segment];
            if (!is_array($next)) {
                return false;
            }

            $current = $next;
        }

        return false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = $this->segments($key);
        $current = $this->items;

        foreach ($segments as $index => $segment) {
            if (!array_key_exists($segment, $current)) {
                return $default;
            }

            $value = $current[$segment];
            if ($index === array_key_last($segments)) {
                return $value;
            }

            if (!is_array($value)) {
                return $default;
            }

            $current = $value;
        }

        return $default;
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            throw new ConfigException(sprintf('Configuration "%s" must be a string.', $key));
        }

        return $value;
    }

    public function requireBool(string $key): bool
    {
        $value = $this->get($key);
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Configuration "%s" must be a boolean.', $key));
        }

        return $value;
    }

    public function requireInt(string $key): int
    {
        $value = $this->get($key);
        if (!is_int($value)) {
            throw new ConfigException(sprintf('Configuration "%s" must be an integer.', $key));
        }

        return $value;
    }

    public function environment(): Environment
    {
        return Environment::parse($this->requireString('app.environment'));
    }

    /** @return non-empty-list<string> */
    private function segments(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new ConfigException('Configuration key cannot be empty.');
        }

        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new ConfigException(sprintf('Configuration key "%s" contains an empty segment.', $key));
            }
        }

        return $segments;
    }
}
