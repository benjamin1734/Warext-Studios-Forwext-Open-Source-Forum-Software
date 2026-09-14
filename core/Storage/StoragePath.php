<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

use InvalidArgumentException;
use Stringable;

final readonly class StoragePath implements Stringable
{
    private const MAX_LENGTH = 1024;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (
            $value === ''
            || strlen($value) > self::MAX_LENGTH
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Storage path is invalid.');
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || strlen($segment) > 191
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $segment) !== 1
            ) {
                throw new InvalidArgumentException('Storage path contains an invalid segment.');
            }
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @return non-empty-list<string> */
    public function segments(): array
    {
        /** @var non-empty-list<string> $segments */
        $segments = explode('/', $this->value);
        return $segments;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
