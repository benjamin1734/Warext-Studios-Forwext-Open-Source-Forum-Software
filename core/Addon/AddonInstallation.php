<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;

final readonly class AddonInstallation
{
    public function __construct(
        public AddonManifest $manifest,
        public AddonState $state,
        public AddonDataState $dataState,
        public string $packageChecksum,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $this->packageChecksum) !== 1) {
            throw new InvalidArgumentException('Installed add-on package checksum is invalid.');
        }
    }

    /** @return array<string,string> */
    public function auditSnapshot(): array
    {
        return [
            'id'=>$this->manifest->id->value(),
            'version'=>$this->manifest->version->value(),
            'state'=>$this->state->value,
            'data_state'=>$this->dataState->value,
            'package_checksum'=>$this->packageChecksum,
        ];
    }
}
