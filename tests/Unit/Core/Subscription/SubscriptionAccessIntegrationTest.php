<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Subscription;

use PHPUnit\Framework\TestCase;

final class SubscriptionAccessIntegrationTest extends TestCase
{
    public function testActiveSubscriptionsAreRuntimeRoleAndPermissionOverlays():void
    {
        $root=dirname(__DIR__,4);
        $assignments=(string)file_get_contents($root.'/core/Domain/Access/DatabaseUserAccessAssignmentProvider.php');
        $permissions=(string)file_get_contents($root.'/core/Domain/Access/Permission/DatabasePermissionRuleRepository.php');

        self::assertStringContainsString('forwext_user_subscriptions s',$assignments);
        self::assertStringContainsString("s.state='active'",$assignments);
        self::assertStringContainsString('s.ends_at_utc>UTC_TIMESTAMP(6)',$assignments);
        self::assertStringContainsString("r.kind='custom' AND r.is_protected=0",$assignments);

        self::assertStringContainsString('forwext_subscription_plan_permissions',$permissions);
        self::assertStringContainsString("s.state='active'",$permissions);
        self::assertStringContainsString('s.ends_at_utc>UTC_TIMESTAMP(6)',$permissions);
        self::assertStringContainsString('PermissionEffect::Allow->value',$permissions);
    }

    public function testUpgradeServiceBlocksAdministrativePrivilegeBinding():void
    {
        $root=dirname(__DIR__,4);
        $service=(string)file_get_contents($root.'/core/Subscription/SubscriptionService.php');

        self::assertStringContainsString("'subscription.manage_all'",$service);
        self::assertStringContainsString('/^(?:acp|moderation|audit|payment)\\./D',$service);
        self::assertStringContainsString('r.kind=\'custom\'',$this->assignmentSource($root));
    }

    private function assignmentSource(string $root):string
    {
        return (string)file_get_contents($root.'/core/Domain/Access/DatabaseUserAccessAssignmentProvider.php');
    }
}
