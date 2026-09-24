<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AdminCommunityWebSurfaceTest extends TestCase
{
    public function testUsersAccessForumsContentAndModerationAreNativeAcpRoutes(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $section = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunitySection.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');

        self::assertStringContainsString('AdminCommunitySection::cases()', $factory);
        self::assertStringContainsString('$adminCommunityCsrf', $factory);
        foreach (['users','access','forums','content','moderation'] as $path) {
            self::assertStringContainsString("case " . ucfirst($path), $section);
            self::assertStringContainsString("/admin/' . \$this->value", $section);
        }
        self::assertStringContainsString('HttpMethod::Post', $handler);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('Permission analyzer', $html);
        self::assertStringContainsString('/moderation/discipline', $html);
        self::assertStringContainsString('/moderation/audit', $html);
        self::assertStringContainsString('/content-manager', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testBanAndSuspensionStayInDisciplineWorkflow(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');

        self::assertStringContainsString('Suspensions and bans must use the moderation discipline workflow.', $service);
        self::assertStringContainsString('Moderation-restricted account state must be changed through Discipline', $service);
        self::assertStringContainsString('Ban / warning / restriction', $html);
        self::assertStringNotContainsString("UPDATE forwext_users SET status='banned'", $service);
    }

    public function testAcpMutationsUseCommonPermissionCsrfAndAudit(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHandler.php');

        self::assertStringContainsString("MANAGE_PERMISSION = 'acp.manage'", $service);
        self::assertStringContainsString('$this->audit->mutate', $service);
        self::assertStringContainsString('HttpAuditRequestId::fromRequest', $handler);
        self::assertStringContainsString('Administrators cannot rewrite their own access assignment', $service);
        self::assertStringContainsString('Administrators cannot change their own account state', $service);
    }
}
