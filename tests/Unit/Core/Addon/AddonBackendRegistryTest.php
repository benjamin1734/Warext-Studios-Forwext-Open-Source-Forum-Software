<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendRegistration;
use Forwext\Core\Addon\Backend\AddonBackendRegistry;
use Forwext\Core\Addon\Backend\AddonSettingDefinition;
use Forwext\Core\Addon\Backend\AddonSettingType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonBackendRegistryTest extends TestCase
{
    public function testRegistryOrdersAddonsAndAggregatesTypedMetadata(): void
    {
        $zeta = new AddonBackendRegistration(AddonId::fromString('Zeta/Tool'));
        $zeta->setting(new AddonSettingDefinition(
            'addon.zeta.tool.enabled',
            'Enabled',
            'Enable Zeta Tool.',
            AddonSettingType::Flag,
            true,
        ));
        $acme = new AddonBackendRegistration(AddonId::fromString('Acme/Demo'));
        $acme->setting(new AddonSettingDefinition(
            'addon.acme.demo.enabled',
            'Enabled',
            'Enable Acme Demo.',
            AddonSettingType::Flag,
            false,
        ));

        $registry = new AddonBackendRegistry();
        $registry->register($zeta);
        $registry->register($acme);

        self::assertSame(['Acme/Demo', 'Zeta/Tool'], array_map(
            static fn (AddonBackendRegistration $registration): string => $registration->addonId->value(),
            $registry->all(),
        ));
        self::assertSame(
            ['addon.acme.demo.enabled', 'addon.zeta.tool.enabled'],
            array_map(static fn (AddonSettingDefinition $setting): string => $setting->key, $registry->settings()),
        );
    }

    public function testDuplicateAddonRegistrationFailsClosed(): void
    {
        $registry = new AddonBackendRegistry();
        $registry->register(new AddonBackendRegistration(AddonId::fromString('Acme/Demo')));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new AddonBackendRegistration(AddonId::fromString('Acme/Demo')));
    }
}
