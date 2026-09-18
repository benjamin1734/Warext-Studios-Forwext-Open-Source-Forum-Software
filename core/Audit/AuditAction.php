<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use InvalidArgumentException;
use Stringable;

final readonly class AuditAction implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9._-]{1,95}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Audit action is invalid.');
        }
        return new self($value);
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
