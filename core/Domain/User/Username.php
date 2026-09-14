<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use InvalidArgumentException;
use Stringable;

final readonly class Username implements Stringable
{
    private const MIN_CHARACTERS = 3;
    private const MAX_CHARACTERS = 32;

    private function __construct(
        private string $display,
        private string $key,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Username must be valid UTF-8.');
        }

        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value);
        if (!is_string($trimmed) || $trimmed === '') {
            throw new InvalidArgumentException('Username cannot be empty.');
        }

        $unicode = preg_match('/[^\x00-\x7F]/', $trimmed) === 1;
        $display = $unicode ? self::normalizeUnicode($trimmed) : $trimmed;

        $count = preg_match_all('/./us', $display, $matches);
        if ($count === false || $count < self::MIN_CHARACTERS || $count > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException('Username must be between 3 and 32 Unicode characters.');
        }
        if (preg_match('/\A[\p{L}\p{N}](?:[\p{L}\p{N}._-]*[\p{L}\p{N}])\z/u', $display) !== 1) {
            throw new InvalidArgumentException(
                'Username may contain letters, numbers, dot, underscore and hyphen and must start/end alphanumeric.',
            );
        }

        $key = $unicode ? self::caseFoldUnicode($display) : strtolower($display);
        if (strlen($key) > 191) {
            throw new InvalidArgumentException('Canonical username exceeds the storage limit.');
        }

        return new self($display, $key);
    }

    public static function fromStored(string $display, string $key): self
    {
        $username = self::fromString($display);
        if (!hash_equals($username->key, $key)) {
            throw new InvalidArgumentException('Stored username canonical key does not match its display value.');
        }
        return $username;
    }

    public function display(): string
    {
        return $this->display;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function __toString(): string
    {
        return $this->display;
    }

    private static function normalizeUnicode(string $value): string
    {
        if (!class_exists('Normalizer') || !method_exists('Normalizer', 'normalize')) {
            throw new InvalidArgumentException('Unicode usernames require the Intl extension for canonical normalization.');
        }

        $form = defined('Normalizer::FORM_KC') ? constant('Normalizer::FORM_KC') : 32;
        $normalized = call_user_func(['Normalizer', 'normalize'], $value, $form);
        if (!is_string($normalized) || $normalized === '') {
            throw new InvalidArgumentException('Username Unicode normalization failed.');
        }

        return $normalized;
    }

    private static function caseFoldUnicode(string $value): string
    {
        if (!function_exists('mb_convert_case') || !defined('MB_CASE_FOLD')) {
            throw new InvalidArgumentException('Unicode usernames require Mbstring Unicode case folding.');
        }

        $folded = mb_convert_case($value, constant('MB_CASE_FOLD'), 'UTF-8');
        if ($folded === '') {
            throw new InvalidArgumentException('Username Unicode case folding failed.');
        }

        return $folded;
    }
}
