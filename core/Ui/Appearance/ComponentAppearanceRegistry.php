<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ComponentAppearanceRegistry
{
    /** @var array<string, ComponentAppearanceDefinition> */
    private array $definitions;

    /**
     * @param list<ComponentAppearanceDefinition> $definitions
     */
    public function __construct(
        public readonly int $manifestVersion,
        private readonly DesignTokenCatalog $tokens,
        array $definitions,
    ) {
        if ($this->manifestVersion !== 1) {
            throw new InvalidArgumentException('Unsupported component appearance manifest version.');
        }

        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->target->value])) {
                throw new InvalidArgumentException(
                    'Duplicate component appearance target: ' . $definition->target->value,
                );
            }

            foreach ($definition->bindings as $tokenKey) {
                $this->tokens->definition($tokenKey);
            }

            $indexed[$definition->target->value] = $definition;
        }

        foreach (ComponentAppearanceTarget::cases() as $requiredTarget) {
            if (!isset($indexed[$requiredTarget->value])) {
                throw new InvalidArgumentException(
                    'Missing required component appearance target: ' . $requiredTarget->value,
                );
            }
        }

        ksort($indexed, SORT_STRING);
        $this->definitions = $indexed;
    }

    public static function coreDefaults(DesignTokenCatalog $tokens): self
    {
        $path = dirname(__DIR__, 3) . '/resources/appearance/forwext-components-default.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Core component appearance manifest could not be read.');
        }

        return self::fromJson($json, $tokens);
    }

    public static function fromJson(string $json, DesignTokenCatalog $tokens): self
    {
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Component appearance manifest is not valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($manifest)) {
            throw new InvalidArgumentException('Component appearance manifest must be an object.');
        }

        $version = $manifest['version'] ?? null;
        $components = $manifest['components'] ?? null;
        if (!is_int($version) || !is_array($components) || !array_is_list($components)) {
            throw new InvalidArgumentException('Component appearance manifest shape is invalid.');
        }

        $definitions = [];
        foreach ($components as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Component appearance entry must be an object.');
            }

            $rawTarget = $entry['target'] ?? null;
            $bindings = $entry['tokens'] ?? null;
            if (!is_string($rawTarget) || !is_array($bindings) || array_is_list($bindings)) {
                throw new InvalidArgumentException('Component appearance entry fields are invalid.');
            }

            $target = ComponentAppearanceTarget::tryFrom($rawTarget);
            if (!$target instanceof ComponentAppearanceTarget) {
                throw new InvalidArgumentException('Unknown component appearance target.');
            }

            $typedBindings = [];
            foreach ($bindings as $property => $tokenKey) {
                if (!is_string($property) || !is_string($tokenKey)) {
                    throw new InvalidArgumentException('Component appearance binding is invalid.');
                }

                $typedBindings[$property] = $tokenKey;
            }

            $definitions[] = new ComponentAppearanceDefinition($target, $typedBindings);
        }

        return new self($version, $tokens, $definitions);
    }

    /** @return list<ComponentAppearanceDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function definition(ComponentAppearanceTarget $target): ComponentAppearanceDefinition
    {
        return $this->definitions[$target->value];
    }
}
