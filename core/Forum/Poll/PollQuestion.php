<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use InvalidArgumentException;

final readonly class PollQuestion
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Poll question must contain 1-255 safe UTF-8 bytes.');
        }
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
