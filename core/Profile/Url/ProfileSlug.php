<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use Stringable;

final readonly class ProfileSlug implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (
            strlen($value) < 3
            || strlen($value) > 32
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/D', $value) !== 1
            || str_contains($value, '--')
        ) {
            throw new ProfileUrlException(
                'Custom profile slug must be 3-32 ASCII lowercase letters, digits or single hyphens.',
            );
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
