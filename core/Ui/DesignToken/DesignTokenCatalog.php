<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\DesignToken;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class DesignTokenCatalog
{
    private static ?self $coreDefaults = null;

    /** @var array<string, DesignTokenDefinition> */
    private array $definitions;

    /**
     * @param list<DesignTokenDefinition> $definitions
     */
    public function __construct(
        public readonly int $manifestVersion,
        array $definitions,
    ) {
        if ($this->manifestVersion !== 1) {
            throw new InvalidArgumentException('Unsupported design token manifest version.');
        }

        if ($definitions === []) {
            throw new InvalidArgumentException('Design token catalog cannot be empty.');
        }

        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw new InvalidArgumentException('Duplicate design token key: ' . $definition->key);
            }
            $indexed[$definition->key] = $definition;
        }

        ksort($indexed, SORT_STRING);
        $this->definitions = $indexed;
        $this->assertReferences();
    }

    public static function coreDefaults(): self
    {
        if (self::$coreDefaults instanceof self) {
            return self::$coreDefaults;
        }

        $path = dirname(__DIR__, 3) . '/resources/design-tokens/forwext-default.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Core design token manifest could not be read.');
        }

        return self::$coreDefaults = self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Design token manifest is not valid JSON.', previous: $exception);
        }

        if (!is_array($manifest)) {
            throw new InvalidArgumentException('Design token manifest must be an object.');
        }

        $version = $manifest['version'] ?? null;
        $tokens = $manifest['tokens'] ?? null;
        if (!is_int($version) || !is_array($tokens) || !array_is_list($tokens)) {
            throw new InvalidArgumentException('Design token manifest shape is invalid.');
        }

        $definitions = [];
        foreach ($tokens as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Design token entry must be an object.');
            }

            $key = $entry['key'] ?? null;
            $rawCategory = $entry['category'] ?? null;
            $value = $entry['value'] ?? null;
            $reference = $entry['ref'] ?? null;

            if (
                !is_string($key)
                || !is_string($rawCategory)
                || ($value !== null && !is_string($value))
                || ($reference !== null && !is_string($reference))
            ) {
                throw new InvalidArgumentException('Design token entry contains invalid fields.');
            }

            $category = DesignTokenCategory::tryFrom($rawCategory);
            if (!$category instanceof DesignTokenCategory) {
                throw new InvalidArgumentException('Design token category is unknown.');
            }

            $definitions[] = new DesignTokenDefinition($key, $category, $value, $reference);
        }

        return new self($version, $definitions);
    }

    /** @return list<DesignTokenDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function definition(string $key): DesignTokenDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException('Unknown design token: ' . $key);
    }

    public function resolveValue(string $key): string
    {
        return $this->resolve($key, []);
    }

    /** @param array<string, true> $visiting */
    private function resolve(string $key, array $visiting): string
    {
        if (isset($visiting[$key])) {
            throw new InvalidArgumentException('Design token reference cycle detected.');
        }

        $definition = $this->definition($key);
        if ($definition->reference === null) {
            return $definition->value
                ?? throw new RuntimeException('Design token definition has no value.');
        }

        $visiting[$key] = true;

        return $this->resolve($definition->reference, $visiting);
    }

    private function assertReferences(): void
    {
        foreach ($this->definitions as $definition) {
            if ($definition->reference !== null && !isset($this->definitions[$definition->reference])) {
                throw new InvalidArgumentException(
                    'Design token references an unknown token: ' . $definition->reference,
                );
            }

            $this->resolveValue($definition->key);
        }
    }
}
