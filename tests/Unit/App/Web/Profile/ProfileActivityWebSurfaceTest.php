<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use Forwext\App\Web\Profile\ActivityFeedHandler;
use Forwext\App\Web\Profile\ProfileActivityCsrfTokenHandler;
use Forwext\App\Web\Profile\ProfileActivityDeleteHandler;
use Forwext\App\Web\Profile\ProfileActivitySettingsHandler;
use Forwext\App\Web\Profile\ProfileCommentsHandler;
use Forwext\App\Web\Profile\ProfilePostReactionHandler;
use Forwext\App\Web\Profile\ProfilePostsHandler;
use PHPUnit\Framework\TestCase;

final class ProfileActivityWebSurfaceTest extends TestCase
{
    public function testNativeProfileActivityHandlersAreAutoloadable(): void
    {
        self::assertTrue(class_exists(ProfilePostsHandler::class));
        self::assertTrue(class_exists(ProfileCommentsHandler::class));
        self::assertTrue(class_exists(ProfilePostReactionHandler::class));
        self::assertTrue(class_exists(ProfileActivityDeleteHandler::class));
        self::assertTrue(class_exists(ProfileActivitySettingsHandler::class));
        self::assertTrue(class_exists(ProfileActivityCsrfTokenHandler::class));
        self::assertTrue(class_exists(ActivityFeedHandler::class));
    }
}
