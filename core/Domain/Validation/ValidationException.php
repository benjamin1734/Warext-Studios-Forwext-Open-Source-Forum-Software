<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @var list<ValidationViolation> */
    private array $violations;

    /** @param list<ValidationViolation> $violations */
    public function __construct(array $violations)
    {
        if ($violations === []) {
            throw new \InvalidArgumentException('ValidationException requires at least one violation.');
        }

        $this->violations = $violations;
        parent::__construct(sprintf('Validation failed with %d violation(s).', count($violations)));
    }

    /** @return list<ValidationViolation> */
    public function violations(): array
    {
        return $this->violations;
    }
}
