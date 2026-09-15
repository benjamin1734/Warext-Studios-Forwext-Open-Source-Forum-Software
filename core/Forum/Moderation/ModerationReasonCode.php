<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use InvalidArgumentException;

final readonly class ModerationReasonCode
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Moderation reason code is invalid.');
        }
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
