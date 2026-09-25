<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use JsonException;

final class AddonUiReactManifestExporter
{
    /** @return array{schema:int,addons:list<array<string,mixed>>,assets:list<array<string,string>>} */
    public function export(AddonUiRegistry $enabled, AddonUiAssetManifest $assets): array
    {
        $compiled = [];
        foreach ($assets->assets as $asset) {
            $compiled[$asset->key] = $asset;
        }

        $addons = [];
        foreach ($enabled->all() as $registration) {
            $addons[] = [
                'id'=>$registration->addonId->value(),
                'namespace'=>$registration->ownerKey(),
                'slots'=>array_map(static fn ($slot): array => [
                    'key'=>$slot->key,
                    'region'=>$slot->region->value,
                    'order'=>$slot->order,
                ], $registration->slots()),
                'widgets'=>array_map(static fn ($widget): array => [
                    'key'=>$widget->key(),
                    'slot'=>$widget->slot(),
                    'order'=>$widget->order(),
                ], $registration->widgets()),
                'navigation'=>array_map(static fn ($item): array => [
                    'key'=>$item->key,
                    'label'=>$item->label,
                    'path'=>$item->path,
                    'order'=>$item->order,
                    'audience'=>$item->audience->value,
                ], $registration->navigationItems()),
                'editor_extensions'=>array_map(static fn ($extension): array => [
                    'key'=>$extension->key,
                    'label'=>$extension->label,
                    'order'=>$extension->order,
                    'surfaces'=>array_map(static fn ($surface): string => $surface->value, $extension->surfaces),
                ], $registration->editorExtensions()),
                'templates'=>array_map(static fn ($template): string => $template->key, $registration->templates()),
                'design_tokens'=>array_map(static fn ($token): array => [
                    'key'=>$token->key,
                    'category'=>$token->category->value,
                ], $registration->designTokens()),
                'assets'=>array_values(array_filter(array_map(
                    static function (AddonUiAssetDefinition $definition) use ($compiled): ?array {
                        $asset = $compiled[$definition->key] ?? null;
                        return $asset instanceof AddonUiCompiledAsset ? [
                            'key'=>$asset->key,
                            'kind'=>$asset->kind->value,
                            'path'=>$asset->publicPath,
                            'integrity'=>$asset->integrity(),
                        ] : null;
                    },
                    $registration->assets(),
                ))),
            ];
        }

        return [
            'schema'=>1,
            'addons'=>$addons,
            'assets'=>array_map(static fn (AddonUiCompiledAsset $asset): array => [
                'key'=>$asset->key,
                'kind'=>$asset->kind->value,
                'path'=>$asset->publicPath,
                'integrity'=>$asset->integrity(),
            ], $assets->assets),
        ];
    }

    public function json(AddonUiRegistry $enabled, AddonUiAssetManifest $assets): string
    {
        try {
            return json_encode($this->export($enabled, $assets), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode add-on UI React manifest.', previous:$exception);
        }
    }
}
