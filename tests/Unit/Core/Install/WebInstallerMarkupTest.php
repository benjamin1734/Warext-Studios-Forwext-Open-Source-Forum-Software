<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;

final class WebInstallerMarkupTest extends TestCase
{
    public function testFirstPartyModuleSectionUsesTheRegisteredDisplayLabelAndKeepsFinalStagesReachable(): void
    {
        $path = dirname(__DIR__, 4) . '/public/install.php';
        $template = file_get_contents($path);

        self::assertIsString($template);
        self::assertStringContainsString('$module->label', $template);
        self::assertStringNotContainsString('$module->name', $template);

        $modules = strpos($template, '6. İlk modüller');
        $theme = strpos($template, '7. Tema başlangıcı');
        $finish = strpos($template, 'Forwext’i Kur');

        self::assertIsInt($modules);
        self::assertIsInt($theme);
        self::assertIsInt($finish);
        self::assertLessThan($theme, $modules);
        self::assertLessThan($finish, $theme);
    }
}
