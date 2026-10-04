<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Search;

use PHPUnit\Framework\TestCase;

final class SearchWebSurfaceTest extends TestCase
{
    public function testNativeSearchSurfaceIsRoutedAndDoesNotExposeScopeInput(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Search/SearchHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Search/SearchHtml.php');

        self::assertStringContainsString("new PathTemplate('/search')", $factory);
        self::assertStringContainsString('new PermissionAwareSearchService', $factory);
        self::assertStringNotContainsString('scalar($query, \'scope\')', $handler);
        self::assertStringNotContainsString('name="scope"', $html);
        self::assertStringContainsString('name="prefix"', $html);
        self::assertStringContainsString('name="tag"', $html);
        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('surface-tabs search-tabs', $html);
        self::assertStringContainsString('<h1>Ara</h1>', $html);
        self::assertStringNotContainsString('forum-eyebrow">KEŞİF', $html);
        self::assertStringContainsString('surface-pagination search-pagination', $html);
        self::assertStringContainsString('$actor = $this->viewers->resolve($request);', $handler);
        self::assertStringContainsString('$fetchLimit = $pageSize + 1;', $handler);
        self::assertStringContainsString('$hasMore = count($hits) > $pageSize;', $handler);
    }
}
