<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Social;

use Forwext\App\Web\Social\InteractionCsrfTokenHandler;
use Forwext\App\Web\Social\PostBookmarkHandler;
use Forwext\App\Web\Social\PostReactionHandler;
use Forwext\App\Web\Social\UserRelationshipHandler;
use PHPUnit\Framework\TestCase;

final class SocialInteractionWebSurfaceTest extends TestCase
{
    public function testNativeHandlersExistForAllMutationSurfaces(): void
    {
        self::assertTrue(class_exists(PostReactionHandler::class));
        self::assertTrue(class_exists(PostBookmarkHandler::class));
        self::assertTrue(class_exists(UserRelationshipHandler::class));
        self::assertTrue(class_exists(InteractionCsrfTokenHandler::class));
    }
}
