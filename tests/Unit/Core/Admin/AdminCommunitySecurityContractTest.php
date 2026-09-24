<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use PHPUnit\Framework\TestCase;

final class AdminCommunitySecurityContractTest extends TestCase
{
    public function testPermissionAnalyzerUsesTheRealPermissionEngineAndAssignmentProvider(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');

        self::assertStringContainsString(
            'new PermissionAnalyzer(new PermissionEngine(new DatabasePermissionRuleRepository($database)))',
            $factory,
        );
        self::assertStringContainsString('new DatabaseUserAccessAssignmentProvider($database)', $factory);
        self::assertStringContainsString('$this->permissionAnalyzer->analyze(', $service);
    }

    public function testSensitiveOperationalCountsAreCapabilityGated(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');

        self::assertStringContainsString("'moderation'=>\$this->allows(\$actor, 'moderation.access')", $service);
        self::assertStringContainsString("'discipline'=>\$this->allows(\$actor, 'moderation.discipline.view')", $service);
        self::assertStringContainsString("'ban'=>\$this->allows(\$actor, 'moderation.ban.manage')", $service);
        self::assertStringContainsString("'audit'=>\$this->allows(\$actor, 'audit.view')", $service);
        self::assertStringContainsString("'content_manager'=>\$this->allows(\$actor, 'content_manager.access')", $service);
    }

    public function testForumMutationsReuseHierarchyValidatedRepository(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $repository = (string) file_get_contents($root . '/core/Forum/Node/DatabaseForumNodeRepository.php');

        self::assertStringContainsString('$this->nodes->save($node)', $service);
        self::assertStringContainsString('new ForumNodeHierarchy($nodes)', $repository);
        self::assertStringContainsString('Existing forum node type cannot be changed from ACP.', $service);
    }
}
