<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonDependencyResolver;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\Security\AddonCapability;
use Forwext\Core\Addon\Security\AddonCapabilityWarningService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonPackageSecurityTest extends TestCase
{
    public function testCapabilityDisclosureIsTypedAndProducesAdministratorWarnings(): void
    {
        $manifest = $this->manifest('Acme/Demo', '1.0.0', [], ['filesystem','outbound_network','user_ui']);

        self::assertSame(
            [AddonCapability::Filesystem, AddonCapability::OutboundNetwork, AddonCapability::UserUi],
            $manifest->capabilities,
        );
        $warnings = (new AddonCapabilityWarningService())->warnings($manifest);
        self::assertCount(3, $warnings);
        self::assertStringContainsString(
            'SSRF',
            implode(' ', array_map(static fn ($warning): string => $warning->message, $warnings)),
        );
    }

    public function testUnknownCapabilityDisclosureFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manifest('Acme/Demo', '1.0.0', [], ['root_shell']);
    }

    public function testDependencyResolverProducesDependencyFirstOrder(): void
    {
        $base = $this->manifest('Acme/Base', '1.2.0');
        $feature = $this->manifest('Acme/Feature', '2.0.0', ['Acme/Base'=>'^1.0.0']);
        $app = $this->manifest('Acme/App', '1.0.0', ['Acme/Feature'=>'>=2.0.0']);

        $order = (new AddonDependencyResolver())->resolveInstallOrder([$app, $base, $feature]);

        self::assertSame(
            ['Acme/Base','Acme/Feature','Acme/App'],
            array_map(static fn (AddonManifest $manifest): string => $manifest->id->value(), $order),
        );
    }

    /** @param array<string,string> $requires @param list<string> $capabilities */
    private function manifest(
        string $id,
        string $version,
        array $requires = [],
        array $capabilities = [],
    ): AddonManifest {
        return AddonManifest::fromJson((string) json_encode([
            'id'=>$id,
            'version'=>$version,
            'title'=>$id,
            'description'=>'Security test fixture.',
            'requires'=>['forwext'=>'0.0.1','addons'=>$requires],
            'conflicts'=>['addons'=>[]],
            'data_retention'=>'retain_only',
            'capabilities'=>$capabilities,
        ], JSON_THROW_ON_ERROR));
    }
}
