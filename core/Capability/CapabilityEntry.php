<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

use InvalidArgumentException;

final readonly class CapabilityEntry
{
    public function __construct(
        public string $name,
        public bool $available,
        public bool $requiredForMinimumProfile = false,
        public ?string $detail = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Capability name is invalid.');
        }
        if ($detail !== null && (
            $detail === ''
            || strlen($detail) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $detail) === 1
        )) {
            throw new InvalidArgumentException('Capability detail is invalid.');
        }
    }
}
