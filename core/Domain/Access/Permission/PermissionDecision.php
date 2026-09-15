<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

final readonly class PermissionDecision
{
    /** @param list<PermissionTraceEntry> $trace */
    private function __construct(
        private bool $allowed,
        private ?int $numericLimit,
        private string $reason,
        private array $trace,
    ) {
    }

    /** @param list<PermissionTraceEntry> $trace */
    public static function allow(?int $numericLimit, string $reason, array $trace): self
    {
        return new self(true, $numericLimit, $reason, $trace);
    }

    /** @param list<PermissionTraceEntry> $trace */
    public static function deny(string $reason, array $trace = []): self
    {
        return new self(false, null, $reason, $trace);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function numericLimit(): ?int
    {
        return $this->numericLimit;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<PermissionTraceEntry> */
    public function trace(): array
    {
        return $this->trace;
    }
}
