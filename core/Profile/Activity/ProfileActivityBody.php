<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use InvalidArgumentException;

final readonly class ProfileActivityBody
{
    private function __construct(private string $source) {}

    public static function fromString(string $source): self
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > 10000 || preg_match('//u', $source) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1
        ) {
            throw new InvalidArgumentException('Profile activity body must contain 1-10000 UTF-8 bytes and no unsafe control characters.');
        }
        return new self($source);
    }

    public function source(): string { return $this->source; }
}
