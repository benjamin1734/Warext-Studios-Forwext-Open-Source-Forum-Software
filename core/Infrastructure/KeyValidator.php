<?php

declare(strict_types=1);

namespace Forwext\Core\Infrastructure;

use InvalidArgumentException;

final class KeyValidator
{
    public static function cache(string $key): string
    {
        return self::validate($key, 191, 'cache');
    }

    public static function session(string $key): string
    {
        return self::validate($key, 191, 'session');
    }

    public static function lock(string $key): string
    {
        return self::validate($key, 191, 'lock');
    }

    public static function tag(string $key): string
    {
        return self::validate($key, 128, 'cache tag');
    }

    private static function validate(string $key, int $max, string $label): string
    {
        if ($key === '' || strlen($key) > $max || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s key.', $label));
        }

        return $key;
    }
}
