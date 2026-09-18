<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

use InvalidArgumentException;

final readonly class FaqCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $language,
        public FaqVisibility $visibility,
        public int $sortOrder,
        public bool $active,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('FAQ category key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('FAQ category label must contain 1-120 UTF-8 bytes.');
        }
        if (strlen($this->description) > 500) {
            throw new InvalidArgumentException('FAQ category description cannot exceed 500 UTF-8 bytes.');
        }
        self::assertLanguage($this->language);
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('FAQ category sort order is invalid.');
        }
    }

    public static function assertLanguage(string $language): void
    {
        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $language) !== 1) {
            throw new InvalidArgumentException('FAQ language tag is invalid.');
        }
    }
}
