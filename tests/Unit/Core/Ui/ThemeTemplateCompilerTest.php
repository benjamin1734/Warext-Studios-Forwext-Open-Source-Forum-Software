<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Ui\Theme\ThemePayload;
use Forwext\Core\Ui\Theme\ThemeRevision;
use Forwext\Core\Ui\Theme\ThemeTemplateCache;
use Forwext\Core\Ui\Theme\ThemeTemplateCompiler;
use PHPUnit\Framework\TestCase;

final class ThemeTemplateCompilerTest extends TestCase
{
    public function testCompilerProducesEscapingClosureWithoutEval(): void
    {
        $compiler = new ThemeTemplateCompiler();
        $compiled = $compiler->compile('page.shell', '<h1>{{ title }}</h1>');
        $rendered = $compiler->render('<h1>{{ title }}</h1>', ['title' => '<script>alert(1)</script>']);

        self::assertStringContainsString('return static function', $compiled);
        self::assertStringContainsString('htmlspecialchars', $compiled);
        self::assertStringNotContainsString('eval(', $compiled);
        self::assertSame('<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>', $rendered);
    }

    public function testCompiledTemplateCacheRendersAndVerifiesManifest(): void
    {
        $directory = sys_get_temp_dir() . '/forwext-theme-test-' . bin2hex(random_bytes(8));
        try {
            $revision = new ThemeRevision(
                EntityId::fromString(str_repeat('a', 32)),
                EntityId::fromString(str_repeat('b', 32)),
                new ThemePayload(['page.shell' => '<p>{{ content }}</p>'], ['tr' => ['page.title' => 'Başlık']]),
                EntityId::fromString(str_repeat('c', 32)),
                new DateTimeImmutable('2026-09-23T18:00:00+00:00'),
            );
            $cache = new ThemeTemplateCache($directory);
            $cache->compile('default', $revision);

            self::assertSame(
                '<p>&lt;b&gt;safe&lt;/b&gt;</p>',
                $cache->render('default', $revision->revisionId, 'page.shell', ['content' => '<b>safe</b>']),
            );
            self::assertSame('', $cache->asset('default', $revision->revisionId, 'css'));
            self::assertSame('', $cache->asset('default', $revision->revisionId, 'js'));
        } finally {
            self::removeDirectory($directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
