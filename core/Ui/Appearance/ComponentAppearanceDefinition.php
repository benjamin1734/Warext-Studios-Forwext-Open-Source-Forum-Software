<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance;

use InvalidArgumentException;

final readonly class ComponentAppearanceDefinition
{
    /** @var array<string, string> */
    public array $bindings;

    /**
     * @param array<string, string> $bindings
     */
    public function __construct(
        public ComponentAppearanceTarget $target,
        array $bindings,
    ) {
        if ($bindings === []) {
            throw new InvalidArgumentException('Component appearance bindings cannot be empty.');
        }

        $normalized = [];
        foreach ($bindings as $rawProperty => $tokenKey) {
            if (!is_string($rawProperty) || !is_string($tokenKey)) {
                throw new InvalidArgumentException('Component appearance binding must be string based.');
            }

            $property = ComponentAppearanceProperty::tryFrom($rawProperty);
            if (!$property instanceof ComponentAppearanceProperty) {
                throw new InvalidArgumentException('Unknown component appearance property.');
            }

            if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $tokenKey) !== 1) {
                throw new InvalidArgumentException('Component appearance token key is invalid.');
            }

            $normalized[$property->value] = $tokenKey;
        }

        ksort($normalized, SORT_STRING);
        $this->bindings = $normalized;
    }

    public function token(ComponentAppearanceProperty $property): ?string
    {
        return $this->bindings[$property->value] ?? null;
    }
}
