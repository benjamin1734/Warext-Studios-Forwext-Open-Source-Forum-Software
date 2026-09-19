<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final readonly class SpellcheckRequest
{
    public string $language;

    public function __construct(
        public string $text,
        string $language = 'tr-tr',
    ) {
        if ($this->text === '' || strlen($this->text) > 100_000
            || preg_match('//u', $this->text) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->text) === 1
        ) {
            throw new InvalidArgumentException('Spellcheck text is invalid.');
        }
        $this->language = SpellcheckLanguage::normalize($language);
    }
}
