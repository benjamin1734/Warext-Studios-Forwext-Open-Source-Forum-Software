<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonDataState;
use Forwext\Core\Addon\AddonDependencyResolver;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonInstallation;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonState;
use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonDependencyResolverTest extends TestCase
{
    public function testCompatibleRequirementAndForwextMinimumAreAccepted(): void
    {
        $base = self::installation('Acme/Base', '1.5.0', state:AddonState::Enabled);
        $candidate = self::manifest('Acme/Demo', '1.0.0', ['Acme/Base'=>'^1.0.0']);

        (new AddonDependencyResolver())->assertPackageCompatible(
            $candidate,
            ['Acme/Base'=>$base],
            SemanticVersion::parse('1.1.0'),
        );

        self::assertTrue(true);
    }

    public function testMissingRequirementIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AddonDependencyResolver())->assertPackageCompatible(
            self::manifest('Acme/Demo', '1.0.0', ['Acme/Base'=>'*']),
            [],
            SemanticVersion::parse('1.0.0'),
        );
    }

    public function testConflictDeclaredByExistingAddonIsSymmetric(): void
    {
        $legacy = self::installation('Acme/Legacy', '1.0.0', conflicts:['Acme/Demo'=>'*']);

        $this->expectException(InvalidArgumentException::class);
        (new AddonDependencyResolver())->assertPackageCompatible(
            self::manifest('Acme/Demo', '1.0.0'),
            ['Acme/Legacy'=>$legacy],
            SemanticVersion::parse('1.0.0'),
        );
    }

    public function testDependencyCycleIsRejectedBeforeLifecycleMutation(): void
    {
        $base = self::installation('Acme/Base', '1.0.0', requires:['Acme/Demo'=>'*']);

        $this->expectException(InvalidArgumentException::class);
        (new AddonDependencyResolver())->assertPackageCompatible(
            self::manifest('Acme/Demo', '1.0.0', ['Acme/Base'=>'*']),
            ['Acme/Base'=>$base],
            SemanticVersion::parse('1.0.0'),
        );
    }

    public function testEnableRequiresEnabledCompatibleDependencies(): void
    {
        $base = self::installation('Acme/Base', '1.0.0', state:AddonState::Disabled);
        $demo = self::installation('Acme/Demo', '1.0.0', ['Acme/Base'=>'*'], state:AddonState::Disabled);

        $this->expectException(InvalidArgumentException::class);
        (new AddonDependencyResolver())->assertCanEnable(
            AddonId::fromString('Acme/Demo'),
            ['Acme/Base'=>$base,'Acme/Demo'=>$demo],
        );
    }

    /**
     * @param array<string,string> $requires
     * @param array<string,string> $conflicts
     */
    private static function installation(
        string $id,
        string $version,
        array $requires = [],
        array $conflicts = [],
        AddonState $state = AddonState::Disabled,
    ): AddonInstallation {
        return new AddonInstallation(
            self::manifest($id, $version, $requires, $conflicts),
            $state,
            AddonDataState::Retained,
            str_repeat('a', 64),
        );
    }

    /**
     * @param array<string,string> $requires
     * @param array<string,string> $conflicts
     */
    private static function manifest(
        string $id,
        string $version,
        array $requires = [],
        array $conflicts = [],
    ): AddonManifest {
        return AddonManifest::fromJson((string) json_encode([
            'id'=>$id,
            'version'=>$version,
            'title'=>$id,
            'description'=>'Resolver fixture.',
            'requires'=>['forwext'=>'1.0.0','addons'=>$requires],
            'conflicts'=>['addons'=>$conflicts],
            'data_retention'=>'retain_only',
        ], JSON_THROW_ON_ERROR));
    }
}
