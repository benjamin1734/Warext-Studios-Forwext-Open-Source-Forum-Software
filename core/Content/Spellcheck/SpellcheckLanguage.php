<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final class SpellcheckLanguage
{
    public static function normalize(string $language): string
    {
        $language = strtolower(trim($language));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/D', $language) !== 1) {
            throw new InvalidArgumentException('Spellcheck language is invalid.');
        }
        return $language;
    }
}
