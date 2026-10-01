<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class FrontendCssLayeringTest extends TestCase
{
    public function testNativeShellUsesExplicitBaseComponentAndPageLayers(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        foreach (['site-base.css', 'site-components.css', 'site-pages.css'] as $asset) {
            self::assertFileExists($root . '/public/assets/' . $asset);
            self::assertNotSame('', trim((string) file_get_contents($root . '/public/assets/' . $asset)));
        }

        self::assertFileDoesNotExist($root . '/public/assets/site-shell.css');

        $base = strpos($profile, "/assets/site-base.css");
        $components = strpos($profile, "/assets/site-components.css");
        $pages = strpos($profile, "/assets/site-pages.css");

        self::assertIsInt($base);
        self::assertIsInt($components);
        self::assertIsInt($pages);
        self::assertLessThan($components, $base);
        self::assertLessThan($pages, $components);

        self::assertStringContainsString('Forwext base UI layer', (string) file_get_contents($root . '/public/assets/site-base.css'));
        self::assertStringContainsString('Forwext component UI layer', (string) file_get_contents($root . '/public/assets/site-components.css'));
        self::assertStringContainsString('Forwext page UI layer', (string) file_get_contents($root . '/public/assets/site-pages.css'));
    }
}
