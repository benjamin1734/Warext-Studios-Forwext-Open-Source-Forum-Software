<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Ui\AddonUiAssetCompiler;
use Forwext\Core\Addon\Ui\AddonUiAssetDefinition;
use Forwext\Core\Addon\Ui\AddonUiAssetKind;
use Forwext\Core\Addon\Ui\AddonUiReactManifestExporter;
use Forwext\Core\Addon\Ui\AddonUiRegistration;
use Forwext\Core\Addon\Ui\AddonUiRegistry;
use Forwext\Core\Addon\Ui\AddonUiRuntimeComposer;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AddonUiAssetReactCompositionTest extends TestCase
{
    public function testEnabledUiRegistryCompilesSameOriginImmutableAssetsAndExportsReactContract(): void
    {
        $public = sys_get_temp_dir() . '/forwext-addon-ui-' . bin2hex(random_bytes(8));
        mkdir($public, 0700, true);

        try {
            $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));
            $registration
                ->asset(new AddonUiAssetDefinition(
                    'addon.acme.demo.asset.css',
                    AddonUiAssetKind::Css,
                    '.demo{display:block}',
                ))
                ->asset(new AddonUiAssetDefinition(
                    'addon.acme.demo.asset.js',
                    AddonUiAssetKind::JavaScript,
                    'window.ForwextDemo=true;',
                ));

            $registry = new AddonUiRegistry();
            $registry->register($registration);

            $composition = (new AddonUiRuntimeComposer(new AddonUiAssetCompiler($public)))->compose($registry);
            self::assertCount(2, $composition->assets->assets);

            foreach ($composition->assets->assets as $asset) {
                self::assertStringStartsWith('/addon-assets/', $asset->publicPath);
                self::assertStringStartsWith('sha256-', $asset->integrity());
                self::assertFileExists($public . $asset->publicPath);
            }

            $head = $composition->assets->headHtml(new BasePath('/forum'));
            self::assertStringContainsString('/forum/addon-assets/', $head);
            self::assertStringContainsString('integrity="sha256-', $head);
            self::assertStringContainsString('<link rel="stylesheet"', $head);
            self::assertStringContainsString('<script src="', $head);

            $manifest = (new AddonUiReactManifestExporter())->export($registry, $composition->assets);
            self::assertSame(1, $manifest['schema']);
            self::assertSame('Acme/Demo', $manifest['addons'][0]['id']);
            self::assertCount(2, $manifest['assets']);
            self::assertArrayNotHasKey('content', $manifest['assets'][0]);
        } finally {
            $this->remove($public);
        }
    }

    public function testUnsafeExternalCssReferenceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AddonUiAssetDefinition(
            'addon.acme.demo.asset.css',
            AddonUiAssetKind::Css,
            '.demo{background:url(https://evil.example/x.png)}',
        );
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->remove($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
