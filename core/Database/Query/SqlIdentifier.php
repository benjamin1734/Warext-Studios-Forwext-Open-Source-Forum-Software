<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\DatabaseException;

final class SqlIdentifier
{
    public static function quote(string $identifier): string
    {
        return self::quoteSegments($identifier, allowWildcard: false);
    }

    public static function selectable(string $identifier): string
    {
        return self::quoteSegments($identifier, allowWildcard: true);
    }

    private static function quoteSegments(string $identifier, bool $allowWildcard): string
    {
        if ($identifier === '') {
            throw new DatabaseException('SQL identifier cannot be empty.');
        }

        $segments = explode('.', $identifier);
        $last = array_key_last($segments);
        $quoted = [];

        foreach ($segments as $index => $segment) {
            if ($allowWildcard && $segment === '*' && $index === $last) {
                $quoted[] = '*';
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
                throw new DatabaseException(sprintf('Unsafe SQL identifier "%s".', $identifier));
            }

            $quoted[] = '`' . $segment . '`';
        }

        return implode('.', $quoted);
    }
}
