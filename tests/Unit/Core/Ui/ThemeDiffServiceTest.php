<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Theme\ThemeDiffService;
use Forwext\Core\Ui\Theme\ThemePayload;
use PHPUnit\Framework\TestCase;

final class ThemeDiffServiceTest extends TestCase
{
    public function testDiffCoversTemplatePhraseCssAndJsChanges(): void
    {
        $before = new ThemePayload(
            ['page.shell' => 'Before'],
            ['tr' => ['nav.home' => 'Ana Sayfa']],
            'body{color:white}',
            'globalThis.x=1;',
        );
        $after = new ThemePayload(
            ['page.shell' => 'After'],
            ['tr' => ['nav.home' => 'Başlangıç']],
            'body{color:black}',
            'globalThis.x=2;',
        );

        $paths = array_map(
            static fn ($entry): string => $entry->path,
            (new ThemeDiffService())->diff($before, $after),
        );

        self::assertSame(
            ['custom.css', 'custom.js', 'phrase.tr.nav.home', 'template.page.shell'],
            $paths,
        );
    }
}
