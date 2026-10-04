<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class CommunityGroupWebSurfaceTest extends TestCase
{
    public function testReferenceRoutesAreBackedByRepositoryServicePermissionsAndSharedShell(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $service = (string) file_get_contents($root . '/core/CommunityGroup/CommunityGroupService.php');
        $repository = (string) file_get_contents(
            $root . '/core/CommunityGroup/DatabaseCommunityGroupRepository.php',
        );
        $html = (string) file_get_contents($root . '/app/Web/CommunityGroup/CommunityGroupHtml.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $modules = (string) file_get_contents($root . '/core/Module/FirstParty/FirstPartyModuleRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString("new PathTemplate('/groups')", $factory);
        self::assertStringContainsString("new PathTemplate('/groups/mine')", $factory);
        self::assertStringContainsString("new PathTemplate('/groups/{groupId}'", $factory);
        self::assertStringContainsString('$groupCsrf = $this->groupCsrfMiddleware($config);', $factory);
        self::assertStringContainsString('forwext.csrf.groups.v1', $factory);
        self::assertGreaterThanOrEqual(2, substr_count($factory, '[$groupCsrf]'));

        foreach ([
            'group.view',
            'group.create',
            'group.join',
            'group.manage_own',
            'group.moderate_any',
        ] as $permission) {
            self::assertStringContainsString($permission, $service);
        }
        self::assertStringContainsString("joinPolicy", $service);
        self::assertStringContainsString("case 'approve':", $service);
        self::assertStringContainsString("case 'promote':", $service);
        self::assertStringContainsString("case 'demote':", $service);
        self::assertStringContainsString("case 'remove':", $service);

        self::assertStringContainsString('forwext_groups', $repository);
        self::assertStringContainsString('forwext_group_members', $repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString("role_key='owner'", $repository);
        self::assertStringContainsString("state='active'", $repository);

        self::assertStringContainsString('Klanlar & Gruplar', $html);
        self::assertStringContainsString('Klanlarım', $html);
        self::assertStringContainsString('group-member-actions', $html);
        self::assertStringContainsString("'groups' => 'groups'", $profile);
        self::assertStringContainsString('data-nav-section="groups"', $profile);
        self::assertStringContainsString("'groups', 'Klanlar & Gruplar', '/groups'", $navigation);
        self::assertStringContainsString("'groups',", $modules);
        self::assertStringContainsString("routePrefixes:['group.']", $modules);
        self::assertStringContainsString('/* community-groups-v1 */', $css);
    }

    public function testWritesAreCsrfProtectedAndNoOwnerMutationControlIsExposed(): void
    {
        $root = dirname(__DIR__, 4);
        $mine = (string) file_get_contents($root . '/app/Web/CommunityGroup/CommunityGroupMineHandler.php');
        $detail = (string) file_get_contents($root . '/app/Web/CommunityGroup/CommunityGroupDetailHandler.php');
        $service = (string) file_get_contents($root . '/core/CommunityGroup/CommunityGroupService.php');
        $repository = (string) file_get_contents(
            $root . '/core/CommunityGroup/DatabaseCommunityGroupRepository.php',
        );

        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $mine);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $detail);
        self::assertStringContainsString("action === 'join'", $detail);
        self::assertStringContainsString("action === 'leave'", $detail);
        self::assertStringContainsString("action === 'manage_member'", $detail);
        self::assertStringContainsString('owner cannot leave the group', $service);
        self::assertStringContainsString('owner membership cannot be changed', $service);
        self::assertStringContainsString('owner role cannot be changed', $repository);
        self::assertStringNotContainsString("role_key, 'owner'", $detail);
    }
}
