<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ResponsiveAccessibilityWebSurfaceTest extends TestCase
{
    public function testSharedShellExposesKeyboardAndMotionSafetyContracts(): void
    {
        $root=dirname(__DIR__,4);
        $profile=(string)file_get_contents($root.'/app/Web/Profile/ProfileHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-shell.css');

        self::assertStringContainsString('class="skip-link" href="#main-content"',$profile);
        self::assertStringContainsString('<main id="main-content"',$profile);
        self::assertStringContainsString('aria-label="Ana navigasyon"',$profile);
        self::assertStringContainsString('aria-expanded="false"',$profile);

        self::assertStringContainsString('/* accessibility-regression-v1 */',$css);
        self::assertStringContainsString('.skip-link:focus-visible',$css);
        self::assertStringContainsString('@media(pointer:coarse)',$css);
        self::assertStringContainsString('min-height:44px',$css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)',$css);
        self::assertStringContainsString('overflow-x:clip',$css);
    }
}
