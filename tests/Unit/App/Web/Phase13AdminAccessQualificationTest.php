<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase13AdminAccessQualificationTest extends TestCase
{
    public function testInstalledRuntimeCoversGroupsRolesAnalyzerAndMobileAccessWorkspace(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $workflow = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $fixture = (string) file_get_contents($root . '/tools/browser/seed-phase13-fixtures.php');

        self::assertStringContainsString('/admin/access?q=phase13', $live);
        self::assertStringContainsString('Phase 13 Browser Group', $live);
        self::assertStringContainsString('Phase 13 Browser Role', $live);
        self::assertStringContainsString('admin access: analyzer contract failed', $live);
        self::assertStringContainsString('admin access mobile: responsive contract failed', $live);
        self::assertStringContainsString('seed-phase13-fixtures.php', $workflow);
        self::assertStringContainsString('forwext_user_primary_groups', $fixture);
        self::assertStringContainsString('forwext_user_role_assignments', $fixture);
        self::assertStringContainsString('phase13_browser_group', $fixture);
        self::assertStringContainsString('phase13_browser_role', $fixture);
    }

    public function testAccessSnapshotRetainsAnalyzerContextAndRendererUsesDenseRealDataSurfaces(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString("'analyze_user_id'=>$analyzeUserId?->value()", $service);
        self::assertStringContainsString("'permission_key'=>$permissionKey", $service);
        self::assertStringContainsString("'node_id'=>$nodeId?->value()", $service);

        foreach ([
            'ac-access-shell',
            'ac-access-overview',
            'ac-access-create-grid',
            'ac-access-directory-grid',
            'ac-access-role-grid',
            'ac-permission-analyzer',
            'ac-permission-result',
        ] as $marker) {
            self::assertStringContainsString($marker, $html);
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString('save_group', $html);
        self::assertStringContainsString('save_role', $html);
        self::assertStringContainsString('save_appearance', $html);
        self::assertStringContainsString('$analysis->layers()', $html);
        self::assertStringContainsString('acp-access-density-v2', $css);
        self::assertStringContainsString('.ac-access-table{display:block;min-width:0;width:100%}', $css);
        self::assertStringContainsString('content:"Primary"', $css);
        self::assertStringContainsString('content:"Priority"', $css);
    }
}
