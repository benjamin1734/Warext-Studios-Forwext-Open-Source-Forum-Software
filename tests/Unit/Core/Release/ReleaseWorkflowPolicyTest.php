<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Release;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowPolicyTest extends TestCase
{
    public function testRuntimePackageExcludesProjectDocumentation():void
    {
        $root=dirname(__DIR__,4);
        $workflow=(string)file_get_contents($root.'/.github/workflows/build-install-package.yml');

        self::assertStringContainsString("--exclude 'docs/'",$workflow);
        self::assertStringContainsString("--exclude 'CHANGELOG.md'",$workflow);
        self::assertStringContainsString("--exclude 'README.md'",$workflow);
        self::assertStringContainsString("--exclude 'PROJECT_STATUS.md'",$workflow);
        self::assertStringNotContainsString("--exclude 'LICENSE'",$workflow);
    }

    public function testSupersededNormalRunsCancelButReleaseRunsStayProtected():void
    {
        $root=dirname(__DIR__,4);
        $build=(string)file_get_contents($root.'/.github/workflows/build-install-package.yml');
        $database=(string)file_get_contents($root.'/.github/workflows/mysql-migration-smoke.yml');

        self::assertStringContainsString('concurrency:',$build);
        self::assertStringContainsString("startsWith(github.event.head_commit.message, 'release(')",$build);
        self::assertStringContainsString("github.event_name != 'workflow_dispatch'",$build);
        self::assertStringContainsString('cancel-in-progress: true',$database);
        self::assertStringContainsString(
            'A normal later commit with the same VERSION must never publish that version.',
            $build
        );
    }
}
