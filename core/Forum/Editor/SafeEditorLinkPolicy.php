<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final class SafeEditorLinkPolicy
{
    public function normalize(string $value): ?string
    {
        $value = trim($value);
        if (
            $value === ''
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_contains($value, '\\')
        ) {
            return null;
        }

        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || trim((string) $parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $value;
    }
}
