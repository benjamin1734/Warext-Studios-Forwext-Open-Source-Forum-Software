<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\TrustedHost;

use InvalidArgumentException;

final readonly class TrustedHostPolicy
{
    /** @var non-empty-list<string> */
    private array $patterns;

    /** @param non-empty-list<string> $patterns */
    public function __construct(array $patterns)
    {
        $validated = [];
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            if (!$this->isValidPattern($pattern)) {
                throw new InvalidArgumentException(sprintf('Invalid trusted-host pattern "%s".', $pattern));
            }
            $validated[] = $pattern;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('At least one trusted host is required.');
        }

        $this->patterns = array_values(array_unique($validated));
    }

    public function allows(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        foreach ($this->patterns as $pattern) {
            if (!str_starts_with($pattern, '*.')) {
                if (hash_equals($pattern, $host)) {
                    return true;
                }
                continue;
            }

            $suffix = substr($pattern, 1);
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isValidPattern(string $pattern): bool
    {
        if (filter_var($pattern, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (str_starts_with($pattern, '*.')) {
            $pattern = substr($pattern, 2);
        }

        return preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D', $pattern) === 1;
    }
}
