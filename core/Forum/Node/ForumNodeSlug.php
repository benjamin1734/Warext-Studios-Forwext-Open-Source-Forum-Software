<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use InvalidArgumentException;

final readonly class ForumNodeSlug
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (strlen($value) < 1 || strlen($value) > 100
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                'Forum node slug must be 1-100 lowercase ASCII letters, numbers or single hyphen separators.',
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
