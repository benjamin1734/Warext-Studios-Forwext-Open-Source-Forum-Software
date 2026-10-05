<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase12AdminUsersQualificationTest extends TestCase
{
    public function testInstalledRuntimeCoversUserDirectorySelectionAndMobileDetail(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $workflow = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $fixture = (string) file_get_contents($root . '/tools/browser/seed-phase12-fixtures.php');

        self::assertStringContainsString('/admin/users?q=phase12', $live);
        self::assertStringContainsString('phase12-member', $live);
        self::assertStringContainsString('12121212121212121212121212121212', $live);
        self::assertStringContainsString('admin users: selected user contract failed', $live);
        self::assertStringContainsString('admin users mobile: responsive contract failed', $live);
        self::assertStringContainsString('seed-phase12-fixtures.php', $workflow);
        self::assertStringContainsString('forwext_user_history', $fixture);
        self::assertStringContainsString('phase12.browser.fixture', $fixture);
    }

    public function testUserSnapshotKeepsBoundedSearchAndDenseRendererUsesRealAggregateFields(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString("'search'=>$search", $service);
        self::assertStringContainsString("strlen($search) > 80", $service);
        self::assertStringContainsString('$selected->locale()->value()', $html);
        self::assertStringContainsString('$selected->timezone()->value()', $html);
        self::assertStringContainsString('$selected->createdAt()->format', $html);
        self::assertStringContainsString('$selected->updatedAt()->format', $html);
        self::assertStringContainsString('$selected->version()', $html);
        self::assertStringContainsString('replace_access', $html);
        self::assertStringContainsString('change_status', $html);
        self::assertStringContainsString('acp-users-density-v2', $css);
        self::assertStringContainsString('.ac-user-edit-grid', $css);
    }
}
