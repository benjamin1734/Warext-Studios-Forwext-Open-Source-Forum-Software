<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use InvalidArgumentException;

final readonly class CustomFieldKey
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Custom field key must be 2-64 lowercase ASCII identifier characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
