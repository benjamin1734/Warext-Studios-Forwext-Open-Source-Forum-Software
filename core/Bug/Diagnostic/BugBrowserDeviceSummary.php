<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use InvalidArgumentException;

final readonly class BugBrowserDeviceSummary
{
    public function __construct(
        public string $browserFamily,
        public ?int $browserMajor,
        public string $osFamily,
        public string $deviceClass,
        public ?string $userAgentFingerprint,
    ) {
        foreach ([$this->browserFamily,$this->osFamily,$this->deviceClass] as $value) {
            if ($value === '' || strlen($value) > 64) {
                throw new InvalidArgumentException('Bug browser/device summary contains an invalid label.');
            }
        }
        if ($this->browserMajor !== null && ($this->browserMajor < 0 || $this->browserMajor > 10000)) {
            throw new InvalidArgumentException('Bug browser major version is invalid.');
        }
        if ($this->userAgentFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/D', $this->userAgentFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('Bug user-agent fingerprint is invalid.');
        }
    }
}
