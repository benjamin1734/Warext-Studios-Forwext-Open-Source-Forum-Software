<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonDataRetentionPolicy;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonVersion;
use Forwext\Core\Addon\AddonVersionConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonManifestTest extends TestCase
{
    public function testCanonicalManifestParsesDependenciesConflictsAndRetention(): void
    {
        $manifest = AddonManifest::fromJson((string) json_encode([
            'id'=>'Acme/Demo',
            'version'=>'1.2.3',
            'title'=>'Demo',
            'description'=>'Demo add-on.',
            'requires'=>[
                'forwext'=>'1.0.0',
                'addons'=>['Acme/Base'=>'^1.1.0'],
            ],
            'conflicts'=>[
                'addons'=>['Acme/Legacy'=>'<2.0.0'],
            ],
            'data_retention'=>'purge_supported',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('Acme/Demo', $manifest->id->value());
        self::assertSame('1.2.3', $manifest->version->value());
        self::assertSame(AddonDataRetentionPolicy::PurgeSupported, $manifest->dataRetention);
        self::assertTrue($manifest->requires['Acme/Base']->matches(AddonVersion::parse('1.9.0')));
        self::assertFalse($manifest->requires['Acme/Base']->matches(AddonVersion::parse('2.0.0')));
        self::assertTrue($manifest->conflicts['Acme/Legacy']->matches(AddonVersion::parse('1.8.0')));

        $roundTrip = AddonManifest::fromJson($manifest->normalizedJson());
        self::assertSame($manifest->normalizedJson(), $roundTrip->normalizedJson());
    }

    public function testVersionConstraintSupportsExactRangesCaretAndTilde(): void
    {
        self::assertTrue(AddonVersionConstraint::parse('>=1.2.0 <2.0.0')->matches(AddonVersion::parse('1.9.9')));
        self::assertFalse(AddonVersionConstraint::parse('>=1.2.0 <2.0.0')->matches(AddonVersion::parse('2.0.0')));
        self::assertTrue(AddonVersionConstraint::parse('^0.2.3')->matches(AddonVersion::parse('0.2.9')));
        self::assertFalse(AddonVersionConstraint::parse('^0.2.3')->matches(AddonVersion::parse('0.3.0')));
        self::assertTrue(AddonVersionConstraint::parse('~2.4.1')->matches(AddonVersion::parse('2.4.8')));
        self::assertFalse(AddonVersionConstraint::parse('~2.4.1')->matches(AddonVersion::parse('2.5.0')));
    }

    public function testSemverPrereleasePrecedenceAndValidationFollowSemverTwoPointZero(): void
    {
        $ordered = [
            '1.0.0-alpha',
            '1.0.0-alpha.1',
            '1.0.0-alpha.beta',
            '1.0.0-beta',
            '1.0.0-beta.2',
            '1.0.0-beta.11',
            '1.0.0-rc.1',
            '1.0.0',
        ];

        for ($index = 0, $count = count($ordered) - 1; $index < $count; ++$index) {
            self::assertLessThan(
                0,
                AddonVersion::parse($ordered[$index])->compare(AddonVersion::parse($ordered[$index + 1])),
                $ordered[$index] . ' must precede ' . $ordered[$index + 1],
            );
        }

        self::assertTrue(AddonVersion::parse('1.0.0+build.1')->equals(AddonVersion::parse('1.0.0+build.2')));
        self::assertTrue(
            AddonVersionConstraint::parse('>=1.0.0-beta.2 <1.0.0')
                ->matches(AddonVersion::parse('1.0.0-beta.11')),
        );

        $this->expectException(InvalidArgumentException::class);
        AddonVersion::parse('1.0.0-alpha.01');
    }

    public function testManifestRejectsUnknownKeysAndSelfDependency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AddonManifest::fromJson((string) json_encode([
            'id'=>'Acme/Demo',
            'version'=>'1.0.0',
            'title'=>'Demo',
            'requires'=>['forwext'=>'1.0.0','addons'=>['Acme/Demo'=>'*']],
            'conflicts'=>['addons'=>[]],
            'data_retention'=>'retain_only',
            'unsafe'=>'ignored',
        ], JSON_THROW_ON_ERROR));
    }

    public function testAddonIdRejectsPathTraversalShapes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AddonId::fromString('../Demo');
    }
}
