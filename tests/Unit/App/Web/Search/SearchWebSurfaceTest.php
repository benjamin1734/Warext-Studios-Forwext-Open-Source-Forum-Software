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
        self::assertStringNotContainsString("scalar($query, 'scope')", $handler);
        self::assertStringNotContainsString('name="scope"', $html);
        self::assertStringContainsString('name="prefix"', $html);
        self::assertStringContainsString('name="tag"', $html);
        self::assertStringContainsString('type="date"', $html);
    }
}
