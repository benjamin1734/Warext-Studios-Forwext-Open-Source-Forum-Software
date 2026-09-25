<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use RuntimeException;

final readonly class AddonUiAssetCompiler
{
    private string $publicRoot;

    public function __construct(string $publicRoot)
    {
        $root = realpath($publicRoot);
        if (!is_string($root) || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('Add-on UI public asset root is invalid.');
        }
        $this->publicRoot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function compileRegistry(AddonUiRegistry $registry): AddonUiAssetManifest
    {
        $compiled = [];
        foreach ($registry->all() as $registration) {
            foreach ($registration->assets() as $asset) {
                $compiled[] = $this->compile($registration, $asset);
            }
        }

        return new AddonUiAssetManifest($compiled);
    }

    public function compile(AddonUiRegistration $registration, AddonUiAssetDefinition $asset): AddonUiCompiledAsset
    {
        $ownerHash = hash('sha256', strtolower($registration->addonId->value()));
        $directory = $this->publicRoot . DIRECTORY_SEPARATOR . 'addon-assets' . DIRECTORY_SEPARATOR . $ownerHash;
        $this->ensureDirectory($directory);

        $filename = $asset->checksum . '.' . $asset->kind->extension();
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_link($path)) {
            throw new RuntimeException('Compiled add-on asset may not be a symbolic link.');
        }

        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (!is_string($existing) || !hash_equals($asset->checksum, hash('sha256', $existing))) {
                throw new RuntimeException('Existing compiled add-on asset checksum mismatch.');
            }
        } else {
            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                if (file_put_contents($temporary, $asset->content, LOCK_EX) !== strlen($asset->content)) {
                    throw new RuntimeException('Unable to write compiled add-on UI asset.');
                }
                if (!chmod($temporary, 0644) || !rename($temporary, $path)) {
                    throw new RuntimeException('Unable to publish compiled add-on UI asset.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        return new AddonUiCompiledAsset(
            $registration->addonId,
            $asset->key,
            $asset->kind,
            '/addon-assets/' . $ownerHash . '/' . $filename,
            $asset->checksum,
        );
    }

    private function ensureDirectory(string $directory): void
    {
        $base = $this->publicRoot . DIRECTORY_SEPARATOR . 'addon-assets';
        if (is_link($base) || is_link($directory)) {
            throw new RuntimeException('Add-on UI asset directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create add-on UI asset directory.');
        }
    }
}
