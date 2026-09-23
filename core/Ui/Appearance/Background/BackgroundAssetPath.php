<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

use InvalidArgumentException;
use Stringable;

final readonly class BackgroundAssetPath implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (
            strlen($value) < 1
            || strlen($value) > 512
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '..')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match(
                '/^assets\/appearance\/[A-Za-z0-9][A-Za-z0-9_\/-]*\.(?:avif|jpe?g|png|webp)$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException('Appearance background asset path is invalid.');
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
