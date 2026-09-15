<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use InvalidArgumentException;

final readonly class ModerationRequestId
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Moderation request id is invalid.');
        }
        return new self($value);
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function value(): string
    {
        return $this->value;
    }
}
