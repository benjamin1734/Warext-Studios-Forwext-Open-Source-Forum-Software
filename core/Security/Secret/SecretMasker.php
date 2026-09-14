<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

use SensitiveParameter;
use Stringable;

final class SecretMasker
{
    public const MASK = '[REDACTED]';

    /** @var list<string> */
    private array $secrets = [];

    /** @param iterable<string> $secrets */
    public function __construct(iterable $secrets = [])
    {
        foreach ($secrets as $secret) {
            $this->register($secret);
        }
    }

    public function register(#[SensitiveParameter] string $secret): void
    {
        if ($secret === '' || in_array($secret, $this->secrets, true)) {
            return;
        }

        $this->secrets[] = $secret;
        usort($this->secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    }

    public function maskString(string $value): string
    {
        return $this->secrets === [] ? $value : str_replace($this->secrets, self::MASK, $value);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public function maskContext(array $context): array
    {
        $masked = [];
        foreach ($context as $key => $value) {
            $masked[$key] = $this->maskValue($value, is_string($key) ? $key : null);
        }

        return $masked;
    }

    private function maskValue(mixed $value, ?string $key): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if (is_string($value)) {
            return $this->maskString($value);
        }

        if (is_array($value)) {
            return $this->maskContext($value);
        }

        if ($value instanceof Stringable) {
            return $this->maskString((string) $value);
        }

        if (is_object($value)) {
            return sprintf('[OBJECT %s]', $value::class);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|client[_-]?secret)/i',
            $key,
        ) === 1;
    }
}
