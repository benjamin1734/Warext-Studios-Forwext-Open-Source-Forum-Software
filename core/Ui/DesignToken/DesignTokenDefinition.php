<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\DesignToken;

use InvalidArgumentException;

final readonly class DesignTokenDefinition
{
    public function __construct(
        public string $key,
        public DesignTokenCategory $category,
        public ?string $value = null,
        public ?string $reference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Design token key is invalid.');
        }

        if (($this->value === null) === ($this->reference === null)) {
            throw new InvalidArgumentException('Design token must contain exactly one value or reference.');
        }

        if (
            $this->reference !== null
            && preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $this->reference) !== 1
        ) {
            throw new InvalidArgumentException('Design token reference is invalid.');
        }

        if ($this->category === DesignTokenCategory::Semantic && $this->reference === null) {
            throw new InvalidArgumentException('Semantic design tokens must reference another token.');
        }

        if ($this->value !== null) {
            DesignTokenValuePolicy::assertValid($this->category, $this->key, $this->value);
        }
    }

    public function cssVariable(): string
    {
        return '--forwext-' . str_replace('.', '-', $this->key);
    }
}
