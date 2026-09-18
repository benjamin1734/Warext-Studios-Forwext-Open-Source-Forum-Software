<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use InvalidArgumentException;
use Stringable;

final readonly class AuditRequestId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 100
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Audit request id is invalid.');
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

    public function __toString(): string
    {
        return $this->value;
    }
}
