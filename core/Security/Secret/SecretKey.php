<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

use SensitiveParameter;

final readonly class SecretKey
{
    private const BYTES = 32;

    private function __construct(private string $bytes)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    public static function fromBase64(#[SensitiveParameter] string $encoded): self
    {
        $bytes = base64_decode(trim($encoded), true);

        if ($bytes === false || strlen($bytes) !== self::BYTES) {
            throw new SecretException('Forwext master key must decode to exactly 32 bytes.');
        }

        return new self($bytes);
    }

    public function exportBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /** @internal Do not log, serialize or persist this raw value. */
    public function bytesForCrypto(): string
    {
        return $this->bytes;
    }
}
