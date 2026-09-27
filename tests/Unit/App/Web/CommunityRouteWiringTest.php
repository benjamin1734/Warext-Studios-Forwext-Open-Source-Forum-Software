<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class CommunityRouteWiringTest extends TestCase
{
    public function testCoreNavigationPathsHaveConcreteWebRoutes(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');

        preg_match_all(
            "/new NavigationItem\\(\\s*'[^']+'\\s*,\\s*'[^']+'\\s*,\\s*'([^']+)'/",
            $navigation,
            $matches,
        );

        $paths = array_values(array_unique($matches[1] ?? []));
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            self::assertStringContainsString(
                "new PathTemplate('" . $path . "'",
                $factory,
                'Default navigation path is not wired: ' . $path,
            );
        }
    }

    public function testPresenceAndCommunityRoutesAreWiredToExistingHandlers(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("new PathTemplate('/members/online')", $factory);
        self::assertStringContainsString('new OnlineUsersHandler(', $factory);
        self::assertStringContainsString("new PathTemplate('/stats')", $factory);
        self::assertStringContainsString('new ForumStatsHandler(', $factory);
        self::assertStringContainsString("new PathTemplate('/account/presence/heartbeat')", $factory);
        self::assertStringContainsString('new PresenceHeartbeatHandler(', $factory);
        self::assertStringContainsString("new PathTemplate('/account/presence')", $factory);
        self::assertStringContainsString('new PresencePreferenceHandler(', $factory);
    }
}
