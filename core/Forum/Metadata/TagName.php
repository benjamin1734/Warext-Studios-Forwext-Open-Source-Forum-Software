<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use InvalidArgumentException;

final readonly class TagName
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Tag name must contain 1-64 UTF-8 bytes and no control characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
