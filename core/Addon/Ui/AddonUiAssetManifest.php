<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AddonUiAssetManifest
{
    /** @var list<AddonUiCompiledAsset> */
    public array $assets;

    /** @param list<AddonUiCompiledAsset> $assets */
    public function __construct(array $assets)
    {
        $keys = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof AddonUiCompiledAsset) {
                throw new InvalidArgumentException('Add-on asset manifest contains an invalid entry.');
            }
            if (isset($keys[$asset->key])) {
                throw new InvalidArgumentException('Duplicate compiled add-on asset key: ' . $asset->key);
            }
            $keys[$asset->key] = true;
        }
        usort(
            $assets,
            static fn (AddonUiCompiledAsset $a, AddonUiCompiledAsset $b): int =>
                [$a->kind->value, $a->key] <=> [$b->kind->value, $b->key],
        );
        $this->assets = array_values($assets);
    }

    public function headHtml(BasePath $basePath): string
    {
        $html = '';
        foreach ($this->assets as $asset) {
            $path = htmlspecialchars($basePath->prepend($asset->publicPath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $integrity = htmlspecialchars($asset->integrity(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($asset->kind === AddonUiAssetKind::Css) {
                $html .= '<link rel="stylesheet" href="' . $path . '" integrity="' . $integrity . '">';
            } else {
                $html .= '<script src="' . $path . '" integrity="' . $integrity . '" defer></script>';
            }
        }

        return $html;
    }
}
