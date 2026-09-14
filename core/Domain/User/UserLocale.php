<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use InvalidArgumentException;
use Stringable;

final readonly class UserLocale implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = str_replace('_', '-', trim($value));
        if ($value === '' || strlen($value) > 32) {
            throw new InvalidArgumentException('Locale is empty or too long.');
        }

        $parts = explode('-', $value);
        $language = array_shift($parts);
        if (!is_string($language) || preg_match('/^[A-Za-z]{2,8}$/D', $language) !== 1) {
            throw new InvalidArgumentException('Locale language subtag is invalid.');
        }

        $canonical = [strtolower($language)];
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z]{4}$/D', $part) === 1) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif (preg_match('/^[A-Za-z]{2}$/D', $part) === 1) {
                $canonical[] = strtoupper($part);
            } elseif (preg_match('/^[0-9]{3}$/D', $part) === 1) {
                $canonical[] = $part;
            } elseif (preg_match('/^[A-Za-z0-9]{1,8}$/D', $part) === 1) {
                $canonical[] = strtolower($part);
            } else {
                throw new InvalidArgumentException('Locale contains an invalid subtag.');
            }
        }

        return new self(implode('-', $canonical));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
