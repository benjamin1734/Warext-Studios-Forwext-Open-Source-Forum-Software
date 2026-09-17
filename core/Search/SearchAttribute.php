<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

final class SearchAttribute
{
    public const FORUM = 'forum';
    public const USER = 'user';
    public const PREFIX = 'prefix';
    public const TAG = 'tag';
    public const STATE = 'state';
    public const THREAD_TYPE = 'thread_type';

    /** @return list<string> */
    public static function filterable(): array
    {
        return [self::FORUM, self::USER, self::PREFIX, self::TAG, self::STATE, self::THREAD_TYPE];
    }

    public static function validateKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Search attribute key is invalid.');
        }
    }

    public static function validateValue(string $value): void
    {
        SearchDocument::validateIdentifier($value, 'attribute value');
    }
}
