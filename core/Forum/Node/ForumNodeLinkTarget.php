<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use InvalidArgumentException;

final readonly class ForumNodeLinkTarget
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Forum link target is empty, too long or contains control characters.');
        }

        if (str_starts_with($value, '/')) {
            if (str_starts_with($value, '//') || str_contains($value, '\\')) {
                throw new InvalidArgumentException('Relative forum link targets must stay inside the current site.');
            }
            return new self($value);
        }

        $parts = parse_url($value);
        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || (string) $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('External forum link targets must be credential-free HTTPS URLs.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isExternal(): bool
    {
        return !str_starts_with($this->value, '/');
    }
}
