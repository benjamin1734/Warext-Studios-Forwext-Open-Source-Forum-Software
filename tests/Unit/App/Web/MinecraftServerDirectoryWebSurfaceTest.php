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
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/vote'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/updates'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/statistics'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/team'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/vote-settings'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}/vote-feed'", $factory);
        self::assertStringContainsString("new PathTemplate('/servers/{serverId}'", $factory);
        self::assertStringContainsString('new DatabaseMinecraftServerRepository($database)', $factory);
        self::assertStringContainsString("listing_state='published'", $repository);
        self::assertStringContainsString('verification_state', $repository);
        self::assertStringContainsString('forwext_minecraft_server_status', $repository);
        self::assertStringContainsString('forwext_minecraft_server_claims', $repository);
        self::assertStringContainsString('forwext_minecraft_server_ownership_events', $repository);
        self::assertStringContainsString('forwext_minecraft_server_votes', $repository);
        self::assertStringContainsString('forwext_minecraft_server_updates', $repository);
        self::assertStringContainsString('forwext_minecraft_server_team_members', $repository);
        self::assertStringContainsString('forwext_minecraft_server_vote_integrations', $repository);
        self::assertStringContainsString('INSERT IGNORE INTO forwext_minecraft_server_votes', $repository);
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
        self::assertStringContainsString('minecraft-server-vote-panel', $html);
        self::assertStringContainsString('minecraft-update-list', $html);
        self::assertStringContainsString('minecraft-stat-grid', $html);
        self::assertStringContainsString('minecraft-vote-trend', $html);
        self::assertStringContainsString('minecraft-update-management', $html);
        self::assertStringContainsString('minecraft-team-list', $html);
        self::assertStringContainsString('minecraft-vote-settings-summary', $html);
        self::assertStringContainsString('Bugün oy ver', $html);
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
        self::assertStringContainsString('/* minecraft-server-voting-v1 */', $css);
        self::assertStringContainsString('/* minecraft-server-updates-statistics-v1 */', $css);
        self::assertStringContainsString('/* minecraft-server-team-vote-integration-v1 */', $css);
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
        self::assertStringContainsString("'forwext_minecraft_server_vote_integrations'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_team_members'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_updates'", $registry);
        self::assertStringContainsString("'forwext_minecraft_server_votes'", $registry);
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

    public function testVotingUsesCsrfPermissionAndDatabaseDailyUniqueness(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $detail = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerDetailHandler.php');
        $vote = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerVoteHandler.php');
        $service = (string) file_get_contents($root . '/core/Minecraft/Server/MinecraftServerService.php');
        $repository = (string) file_get_contents(
            $root . '/core/Minecraft/Server/DatabaseMinecraftServerRepository.php',
        );
        $migration = (string) file_get_contents(
            $root . '/database/migrations/core/CreateMinecraftServerVoting.php',
        );

        self::assertStringContainsString("'server.vote'", $factory);
        self::assertStringContainsString('[HttpMethod::Post]', $factory);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $detail);
        self::assertStringContainsString("minecraft_server.vote", $service);
        self::assertStringContainsString('INSERT IGNORE INTO forwext_minecraft_server_votes', $repository);
        self::assertStringContainsString('uq_forwext_minecraft_server_vote_daily', $migration);
        self::assertStringContainsString('vote_day', $migration);
        self::assertStringContainsString('PermissionDeniedException', $vote);
        self::assertStringContainsString('?vote=', $vote);
    }

    public function testUpdateFeedAndStatisticsUseBackedRoutesAndAuthorizedManagementWrites(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $manage = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerManageHandler.php');
        $service = (string) file_get_contents($root . '/core/Minecraft/Server/MinecraftServerService.php');
        $repository = (string) file_get_contents(
            $root . '/core/Minecraft/Server/DatabaseMinecraftServerRepository.php',
        );
        $html = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerHtml.php');
        $liveSmoke = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString("'server.updates'", $factory);
        self::assertStringContainsString("'server.statistics'", $factory);
        self::assertStringContainsString("action === 'publish_update'", $manage);
        self::assertStringContainsString("action === 'update_state'", $manage);
        self::assertStringContainsString('managementUpdates', $service);
        self::assertStringContainsString('publishUpdate', $service);
        self::assertStringContainsString('changeUpdateState', $service);
        self::assertStringContainsString('forwext_minecraft_server_updates', $repository);
        self::assertStringContainsString('GROUP BY vote_day', $repository);
        self::assertStringContainsString('WHERE server_id=:server_id FOR UPDATE', $repository);
        self::assertStringContainsString('Sunucu Güncellemeleri', $html);
        self::assertStringContainsString('Sunucu İstatistikleri', $html);
        self::assertStringContainsString('minecraft server statistics mobile', $liveSmoke);
    }

    public function testTeamAndVoteIntegrationUseBackedRoutesPermissionsAndBearerFeed(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $team = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerTeamHandler.php');
        $settings = (string) file_get_contents(
            $root . '/app/Web/MinecraftServer/MinecraftServerVoteSettingsHandler.php',
        );
        $feed = (string) file_get_contents(
            $root . '/app/Web/MinecraftServer/MinecraftServerVoteFeedHandler.php',
        );
        $service = (string) file_get_contents($root . '/core/Minecraft/Server/MinecraftServerService.php');
        $repository = (string) file_get_contents(
            $root . '/core/Minecraft/Server/DatabaseMinecraftServerRepository.php',
        );
        $html = (string) file_get_contents($root . '/app/Web/MinecraftServer/MinecraftServerHtml.php');

        self::assertStringContainsString("'server.team'", $factory);
        self::assertStringContainsString("'server.vote-settings'", $factory);
        self::assertStringContainsString("'server.vote-feed'", $factory);
        self::assertStringContainsString(
            '$minecraftServerCsrf = $this->minecraftServerCsrfMiddleware($config);',
            $factory,
        );
        self::assertStringContainsString("forwext.csrf.minecraft-server.v1", $factory);
        self::assertGreaterThanOrEqual(7, substr_count($factory, '[$minecraftServerCsrf]'));
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $team);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $settings);
        self::assertStringContainsString("minecraft_server.team.manage", $service);
        self::assertStringContainsString("minecraft_server.vote_integration.manage", $service);
        self::assertStringContainsString("teamRole($server->serverId, $actor) === 'manager'", $service);
        self::assertStringContainsString('integrationToken()', $service);
        self::assertStringContainsString("hash('sha256', $token)", $service);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('forwext_minecraft_server_team_members', $repository);
        self::assertStringContainsString('forwext_minecraft_server_vote_integrations', $repository);
        self::assertStringContainsString('hash_equals($stored, $tokenHash)', $repository);
        self::assertStringContainsString("'team_member_saved'", $repository);
        self::assertStringContainsString("'team_member_removed'", $repository);
        self::assertStringContainsString("'vote_token_rotated'", $repository);
        self::assertStringContainsString("'vote_integration_on'", $repository);
        self::assertStringContainsString("'vote_integration_off'", $repository);
        self::assertStringContainsString("Authorization: Bearer TOKEN", $html);
        self::assertStringContainsString("preg_match('/^Bearer ", $feed);
        self::assertStringContainsString("'WWW-Authenticate'", $feed);
        self::assertStringContainsString("'bad_request'", $feed);
        self::assertStringContainsString("'account_username'", $feed);
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
