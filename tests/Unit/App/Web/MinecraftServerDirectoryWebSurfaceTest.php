<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class MinecraftServerDirectoryWebSurfaceTest extends TestCase
{
    public function testPublicDirectoryAndDetailRoutesUseRealRepositoryAndSharedShell(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $html = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerHtml.php');
        $repository = (string) file_get_contents(
            $root . '/core/Minecraft/Server/DatabaseMinecraftServerRepository.php',
        );
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');
        $liveSmoke = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString("new PathTemplate('/servers')", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}'", $factory);
        self::assertStringContainsString('new DatabaseMinecraftServerRepository($database)', $factory);
        self::assertStringContainsString("listing_state='published'", $repository);
        self::assertStringContainsString('verification_state', $repository);
        self::assertStringContainsString('forwext_minecraft_server_status', $repository);

        self::assertStringContainsString('Minecraft Sunucuları', $html);
        self::assertStringContainsString('minecraft-server-filter', $html);
        self::assertStringContainsString('minecraft-server-row', $html);
        self::assertStringContainsString('safeExternal', $html);
        self::assertStringNotContainsString('Yeni sunucu ekle', $html);

        self::assertStringContainsString("'servers', 'Sunucular', '/servers'", $navigation);
        self::assertStringContainsString("'servers' => 'servers'", $profile);
        self::assertStringContainsString('data-nav-section="servers"', $profile);

        self::assertStringContainsString('/* minecraft-server-directory-v1 */', $css);
        self::assertStringContainsString('@media(max-width:700px)', $css);
        self::assertStringContainsString('@media(pointer:coarse)', $css);
        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString('minecraft servers: real route did not return HTTP 200', $liveSmoke);
        self::assertStringContainsString('minecraft servers mobile', $liveSmoke);
    }

    public function testFirstPartyModuleOwnsServerRoutesWithoutDeadFutureLinks(): void
    {
        $root = dirname(__DIR__, 4);
        $registry = (string) file_get_contents(
            $root . '/core/Module/FirstParty/FirstPartyModuleRegistry.php',
        );
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString("'minecraft-servers'", $registry);
        self::assertStringContainsString("routePrefixes:['server.']", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_status'", $registry);
        self::assertStringContainsString("'forwext_minecraft_servers'", $registry);
        self::assertStringNotContainsString('/servers/compare', $profile);
        self::assertStringNotContainsString('/servers/seasons', $profile);
    }
}
