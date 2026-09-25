<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Extension;

use PHPUnit\Framework\TestCase;

final class ExtensionApiSecurityContractTest extends TestCase
{
    public function testExtensionCompositionDoesNotReplaceAuthorizationOrProductionOverridePolicy(): void
    {
        $root = dirname(__DIR__, 4);
        $container = (string) file_get_contents($root . '/core/Container/Container.php');
        $events = (string) file_get_contents($root . '/core/Domain/Event/DomainEventDispatcher.php');
        $contract = json_decode(
            (string) file_get_contents($root . '/docs/standards/container-contract.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );

        self::assertStringContainsString('bindExtension(', $container);
        self::assertStringContainsString('decorate(', $container);
        self::assertStringContainsString('CircularDependencyException::fromPath', $container);
        self::assertStringContainsString('assertOverridesAllowed', $container);
        self::assertStringContainsString('listenTyped(', $events);
        self::assertStringContainsString('listenerDiagnostics()', $events);

        self::assertSame(false, $contract['container']['production_rebinding_allowed']);
        self::assertSame(true, $contract['extension_api']['binding_conflicts_fail_closed']);
        self::assertSame(true, $contract['extension_api']['decorator_type_preservation_for_class_or_interface_ids']);
        self::assertSame(true, $contract['authorization']['backend_permission_checks_remain_required']);
        self::assertSame(false, $contract['authorization']['container_resolution_grants_permission']);
        self::assertSame(false, $contract['authorization']['event_listener_registration_grants_permission']);
    }
}
