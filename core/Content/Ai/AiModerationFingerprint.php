<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final class AiModerationFingerprint
{
    public static function forContent(string $contentType, string $text): string
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $contentType) !== 1 || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('AI moderation fingerprint input is invalid.');
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
        return hash('sha256', $contentType . ':' . $normalized);
    }

    public static function assert(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('AI moderation fingerprint is invalid.');
        }
    }
}
