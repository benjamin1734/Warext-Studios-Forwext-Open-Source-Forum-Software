<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

final class NavigationRuntime
{
    /** @var array<string,mixed> */
    private static array $managed = [];

    /** @param array<string,mixed> $managed */
    public static function configure(array $managed): void
    {
        self::$managed = $managed;
    }

    public static function reset(): void
    {
        self::$managed = [];
    }

    /** @param iterable<NavigationContributor> $contributors */
    public static function registry(iterable $contributors = []): NavigationRegistry
    {
        return NavigationRegistry::withCoreDefaults($contributors, self::$managed);
    }

    /** @return array<string,mixed> */
    public static function managed(): array
    {
        return self::$managed;
    }
}
