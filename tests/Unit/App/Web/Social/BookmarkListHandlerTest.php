<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Social;

use Forwext\App\Web\Social\BookmarkListHandler;
use PHPUnit\Framework\TestCase;

final class BookmarkListHandlerTest extends TestCase
{
    public function testBookmarkListHandlerIsAutoloadable(): void
    {
        self::assertTrue(class_exists(BookmarkListHandler::class));
    }
}
