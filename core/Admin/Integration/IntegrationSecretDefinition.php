<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class IntegrationSecretDefinition
{
    public function __construct(
        public string $key,
        public IntegrationSection $section,
        public string $secretName,
        public string $label,
        public string $description,
        public int $maxLength = 16384,
    ) {
        if (preg_match('/^integration\.[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{1,190}$/D', $this->secretName) !== 1
        ) {
            throw new InvalidArgumentException('Integration secret definition is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 120
            || $this->description === '' || strlen($this->description) > 600
            || $this->maxLength < 8 || $this->maxLength > 65536
        ) {
            throw new InvalidArgumentException('Integration secret metadata is invalid.');
        }
    }

    public function normalize(#[SensitiveParameter] mixed $raw): string
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > $this->maxLength || str_contains($raw, "\0")) {
            throw new InvalidArgumentException('Integration secret value is invalid.');
        }

        return $raw;
    }
}
