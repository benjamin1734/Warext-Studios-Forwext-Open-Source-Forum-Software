<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class FrontendPrimitiveCssTest extends TestCase
{
    public function testSharedPrimitivesHaveOneAuthoritativeComponentDefinition(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* shared-primitives-v1 */', $components);

        foreach (['card', 'empty', 'surface-panel', 'fx-btn', 'thread-badge', 'pagination'] as $class) {
            self::assertSame(
                1,
                self::rootClassDefinitionCount($components, $class),
                sprintf('%s must have exactly one component-layer definition.', $class),
            );
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the foundation layer.', $class),
            );
            self::assertSame(
                0,
                self::rootClassDefinitionCount($pages, $class),
                sprintf('%s must not be redefined in the page layer.', $class),
            );
        }
    }

    private static function rootClassDefinitionCount(string $css, string $class): int
    {
        preg_match_all(
            '/(?:^|})\s*\.' . preg_quote($class, '/') . '\s*\{/m',
            $css,
            $matches,
        );

        return count($matches[0] ?? []);
    }
}
