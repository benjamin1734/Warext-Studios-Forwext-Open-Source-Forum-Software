<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Validation;

final readonly class ValidationResult
{
    /** @var list<ValidationViolation> */
    private array $violations;

    /** @param iterable<ValidationViolation> $violations */
    public function __construct(iterable $violations = [])
    {
        $normalized = [];
        foreach ($violations as $violation) {
            $normalized[] = $violation;
        }

        $this->violations = $normalized;
    }

    public static function valid(): self
    {
        return new self();
    }

    public static function invalid(ValidationViolation $violation, ValidationViolation ...$more): self
    {
        return new self([$violation, ...$more]);
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /** @return list<ValidationViolation> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function merged(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }

    public function throwIfInvalid(): void
    {
        if (!$this->isValid()) {
            throw new ValidationException($this->violations);
        }
    }
}
