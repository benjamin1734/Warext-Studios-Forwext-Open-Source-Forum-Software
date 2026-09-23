<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\DesignToken;

use InvalidArgumentException;

final class DesignTokenValuePolicy
{
    public static function assertValid(DesignTokenCategory $category, string $key, string $value): void
    {
        if (
            $value === ''
            || strlen($value) > 160
            || preg_match('/[;{}<>\x00-\x1F\x7F]/', $value) === 1
            || preg_match('/(?:url|expression|var)\s*\(|@import/i', $value) === 1
        ) {
            throw new InvalidArgumentException('Design token value contains unsafe CSS syntax.');
        }

        $valid = match ($category) {
            DesignTokenCategory::Color => preg_match('/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/D', $value) === 1,
            DesignTokenCategory::Typography => self::validTypography($key, $value),
            DesignTokenCategory::Spacing,
            DesignTokenCategory::Radius,
            DesignTokenCategory::Border => self::validLength($value),
            DesignTokenCategory::Shadow => preg_match('/^[A-Za-z0-9#().,%+\- ]+$/D', $value) === 1,
            DesignTokenCategory::Motion => self::validMotion($key, $value),
            DesignTokenCategory::Semantic => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException('Design token value does not match its category policy.');
        }
    }

    private static function validTypography(string $key, string $value): bool
    {
        if (str_contains($key, '.font-family.')) {
            return preg_match('/^[A-Za-z-][A-Za-z0-9 .,-]{0,119}$/D', $value) === 1;
        }

        if (str_contains($key, '.font-weight.')) {
            return preg_match('/^[1-9]00$/D', $value) === 1;
        }

        if (str_contains($key, '.line-height.')) {
            return preg_match('/^(?:[0-9]+(?:\.[0-9]+)?)$/D', $value) === 1;
        }

        return self::validLength($value);
    }

    private static function validMotion(string $key, string $value): bool
    {
        if (str_contains($key, '.duration.')) {
            return preg_match('/^[0-9]{1,4}ms$/D', $value) === 1;
        }

        return preg_match(
            '/^(?:linear|ease|ease-in|ease-out|ease-in-out|cubic-bezier\([0-9., \-]+\))$/D',
            $value,
        ) === 1;
    }

    private static function validLength(string $value): bool
    {
        return preg_match('/^(?:0|[0-9]+(?:\.[0-9]+)?(?:px|rem|em))$/D', $value) === 1;
    }
}
