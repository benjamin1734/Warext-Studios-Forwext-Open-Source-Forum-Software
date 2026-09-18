<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use InvalidArgumentException;

final readonly class SensitiveAuditRedactor implements AuditRedactor
{
    public const REDACTED = '[REDACTED]';
    private const MAX_DEPTH = 12;
    private const MAX_STRING_BYTES = 4096;

    /** @param array<string|int,mixed> $snapshot @return array<string|int,mixed> */
    public function redact(array $snapshot): array
    {
        return $this->walk($snapshot, 0);
    }

    /** @param array<string|int,mixed> $value @return array<string|int,mixed> */
    private function walk(array $value, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Audit snapshot nesting is too deep.');
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidArgumentException('Audit snapshot key is invalid.');
            }
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            $result[$key] = $this->value($item, $depth + 1);
        }
        return $result;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->walk($value, $depth);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Audit snapshots may only contain JSON scalar values and arrays.');
        }
        if (strlen($value) <= self::MAX_STRING_BYTES) {
            return $value;
        }
        return substr($value, 0, self::MAX_STRING_BYTES) . '[TRUNCATED]';
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', trim($key)));

        if (preg_match(
            '/(?:^|_)(?:password|passphrase|secret|token|authorization|cookie|session|credential|'
            . 'private_key|recovery_code|totp|api_key|access_key|client_secret|raw_ip|client_ip|remote_ip|'
            . 'ip_address|email|email_address)(?:_|$)/',
            $normalized,
        ) === 1) {
            return true;
        }

        return in_array($normalized, [
            'ip',
            'raw_ip',
            'client_ip',
            'remote_ip',
            'ip_address',
            'email',
            'email_address',
        ], true);
    }
}
