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
        self::assertStringContainsString("new PathTemplate('/servers/compare')", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/seasons')", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/manage')", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/manage'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/claim'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}'", $factory);
        self::assertStringContainsString('new DatabaseMinecraftServerRepository($database)', $factory);
        self::assertStringContainsString("listing_state='published'", $repository);
        self::assertStringContainsString('verification_state', $repository);
        self::assertStringContainsString('forwext_minecraft_server_status', $repository);
        self::assertStringContainsString('forwext_minecraft_server_claims', $repository);
        self::assertStringContainsString('forwext_minecraft_server_ownership_events', $repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('expectedOwnerUserId', $repository);

        self::assertStringContainsString('Minecraft Sunucuları', $html);
        self::assertStringContainsString('minecraft-server-filter', $html);
        self::assertStringContainsString('minecraft-server-row', $html);
        self::assertStringContainsString('Sunucu Karşılaştırma', $html);
        self::assertStringContainsString('Sunucu Sezonları', $html);
        self::assertStringContainsString('minecraft-compare-options', $html);
        self::assertStringContainsString('minecraft-season-list', $html);
        self::assertStringContainsString('minecraft-manage-form', $html);
        self::assertStringContainsString('minecraft-ownership-panel', $html);
        self::assertStringContainsString('minecraft-claim-form', $html);
        self::assertStringContainsString('minecraft-claim-review-actions', $html);
        self::assertStringContainsString('safeExternal', $html);
        self::assertStringNotContainsString('Yeni sunucu ekle', $html);

        self::assertStringContainsString("'servers', 'Sunucular', '/servers'", $navigation);
        self::assertStringContainsString("'servers' => 'servers'", $profile);
        self::assertStringContainsString('data-nav-section="servers"', $profile);
        self::assertStringContainsString('/servers/compare', $profile);
        self::assertStringContainsString('/servers/seasons', $profile);
        self::assertStringContainsString('/servers/manage', $profile);

        self::assertStringContainsString('/* minecraft-server-directory-v1 */', $css);
        self::assertStringContainsString('@media(max-width:700px)', $css);
        self::assertStringContainsString('@media(pointer:coarse)', $css);
        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString('/* minecraft-server-comparison-seasons-v1 */', $css);
        self::assertStringContainsString('/* minecraft-server-management-v1 */', $css);
        self::assertStringContainsString('minecraft servers: real route did not return HTTP 200', $liveSmoke);
        self::assertStringContainsString('minecraft servers mobile', $liveSmoke);
    }

    public function testFirstPartyModuleOwnsServerRoutesAndSeasonData(): void
    {
        $root = dirname(__DIR__, 4);
        $registry = (string) file_get_contents(
            $root . '/core/Module/FirstParty/FirstPartyModuleRegistry.php',
        );
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString("'minecraft-servers'", $registry);
        self::assertStringContainsString("routePrefixes:['server.']", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_claims'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_ownership_events'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_season_entries'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_seasons'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_status'", $registry);
        self::assertStringContainsString("'forwext_minecraft_servers'", $registry);
        self::assertStringContainsString('/servers/compare', $profile);
        self::assertStringContainsString('/servers/seasons', $profile);
        self::assertStringContainsString('/servers/manage', $profile);
    }

    public function testManagementWritesUseCsrfAndServerSidePermissionLifecycle(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $manage = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerManageHandler.php');
        $claim = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerClaimHandler.php');
        $service = (string) file_get_contents($root . '/core/Minecraft/Server/MinecraftServerService.php');
        $repository = (string) file_get_contents(
            $root . '/core/Minecraft/Server/DatabaseMinecraftServerRepository.php',
        );

        self::assertStringContainsString('[HttpMethod::Get,HttpMethod::Post]', $factory);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $manage);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $claim);
        self::assertStringContainsString("minecraft_server.manage_own", $service);
        self::assertStringContainsString("minecraft_server.manage_any", $service);
        self::assertStringContainsString("minecraft_server.claim", $service);
        self::assertStringContainsString("minecraft_server.transfer", $service);
        self::assertStringContainsString('expectedOwnerUserId', $repository);
        self::assertStringContainsString('WHERE server_id=:server_id FOR UPDATE', $repository);
        self::assertStringContainsString('ownership_transferred', $repository);
        self::assertStringContainsString('claim_approved', $repository);
    }
}
