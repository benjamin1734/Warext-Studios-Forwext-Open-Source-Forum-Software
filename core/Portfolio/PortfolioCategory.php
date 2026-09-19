<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use InvalidArgumentException;

final readonly class PortfolioCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public int $sortOrder,
        public bool $active,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Portfolio category key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('Portfolio category label is invalid.');
        }
        if (strlen($this->description) > 500 || $this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Portfolio category metadata is invalid.');
        }
    }
}
