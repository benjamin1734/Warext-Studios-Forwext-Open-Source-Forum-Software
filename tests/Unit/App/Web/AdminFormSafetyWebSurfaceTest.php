<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AdminFormSafetyWebSurfaceTest extends TestCase
{
    public function testSharedAdminAssetLoadsFormSafetyOnlyOnExistingAdminShell(): void
    {
        $root = dirname(__DIR__, 4);
        $helper = (string) file_get_contents($root . '/app/Web/Admin/AdminAssetsHtml.php');
        $script = (string) file_get_contents($root . '/public/assets/admin-form-safety.js');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('/assets/admin-form-safety.js', $helper);
        self::assertStringContainsString('dataset.forwextSurface', $script);
        self::assertStringContainsString('form.ac-form[method="post"]', $script);
        self::assertStringContainsString('!control.disabled', $script);
        self::assertStringContainsString('control.readOnly', $script);
        self::assertStringContainsString('fingerprint(form)', $script);
        self::assertStringContainsString('form.reset()', $script);
        self::assertStringContainsString('window.addEventListener("beforeunload"', $script);
        self::assertStringContainsString('submittingForm', $script);
        self::assertStringContainsString('role", "status"', $script);
        self::assertStringContainsString('.ac-form-unsaved[hidden]', $css);
        self::assertStringContainsString('.ac-form-is-dirty', $css);
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'));
    }

    public function testBrowserRegressionsUseActualPostFormAndKeepGetFiltersIndependent(): void
    {
        $root = dirname(__DIR__, 4);
        $fixture = (string) file_get_contents($root . '/tools/browser/render-shell-fixtures.php');
        $browser = (string) file_get_contents($root . '/tools/browser/shell-smoke.mjs');
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString('data-browser-acp-editor', $fixture);
        self::assertStringContainsString('/assets/admin-form-safety.js', $fixture);
        self::assertStringContainsString('method="post"', $fixture);
        self::assertStringContainsString('method="get"', $fixture);
        self::assertStringContainsString('new Event("beforeunload"', $browser);
        self::assertStringContainsString('form.ac-form[method=\'get\']', $browser);
        self::assertStringContainsString('select[name="visibility"]', $browser);
        self::assertStringContainsString('input[name="allow_replies"]', $browser);
        self::assertStringContainsString('.ac-forum-editor-form', $live);
        self::assertStringContainsString('originalNodeTitle', $live);
        self::assertStringContainsString('ac-form-unsaved-reset', $live);
    }
}
