<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\Security\AddonCapability;
use PHPUnit\Framework\TestCase;

final class SampleAddonContractTest extends TestCase
{
    public function testHelloWorldSampleManifestStaysCompatibleWithCurrentContract(): void
    {
        $root = dirname(__DIR__, 4);
        $manifest = AddonManifest::fromJson((string) file_get_contents(
            $root . '/examples/addons/Warext/HelloWorld/addon.json',
        ));

        self::assertSame('Warext/HelloWorld', $manifest->id->value());
        self::assertSame([AddonCapability::UserUi], $manifest->capabilities);

        $extension = (string) file_get_contents(
            $root . '/examples/addons/Warext/HelloWorld/src/Extension.php',
        );
        self::assertStringContainsString('AddonUiRegistration', $extension);
        self::assertStringContainsString('new HelloWidget()', $extension);
    }
}
