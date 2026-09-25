<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Addon\AddonId;
use InvalidArgumentException;

final readonly class AddonUiCompiledAsset
{
    public function __construct(
        public AddonId $owner,
        public string $key,
        public AddonUiAssetKind $kind,
        public string $publicPath,
        public string $checksum,
    ) {
        if ($this->publicPath === '' || $this->publicPath[0] !== '/' || str_contains($this->publicPath, '..')) {
            throw new InvalidArgumentException('Compiled add-on asset path is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->checksum) !== 1) {
            throw new InvalidArgumentException('Compiled add-on asset checksum is invalid.');
        }
    }

    public function integrity(): string
    {
        $binary = hex2bin($this->checksum);
        if ($binary === false) {
            throw new InvalidArgumentException('Compiled add-on asset checksum cannot be encoded.');
        }

        return 'sha256-' . base64_encode($binary);
    }
}
