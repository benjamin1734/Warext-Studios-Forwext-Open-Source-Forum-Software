<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final class SpellcheckWord
{
    public static function normalize(string $word): string
    {
        $word = trim($word);
        if ($word === '' || strlen($word) > 96 || preg_match('//u', $word) !== 1) {
            throw new InvalidArgumentException('Spellcheck dictionary word is invalid.');
        }
        if (preg_match('/^\p{L}+(?:[’\'-]\p{L}+)*$/uD', $word) !== 1) {
            throw new InvalidArgumentException('Spellcheck dictionary word contains unsupported characters.');
        }

        $word = strtr($word, [
            'I'=>'ı','İ'=>'i','Ş'=>'ş','Ğ'=>'ğ','Ü'=>'ü','Ö'=>'ö','Ç'=>'ç',
        ]);
        return strtolower($word);
    }

    public static function characters(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $count = preg_match_all('/./us', $value, $matches);
        if ($count === false) {
            throw new InvalidArgumentException('Spellcheck text is invalid UTF-8.');
        }
        return $count;
    }
}
