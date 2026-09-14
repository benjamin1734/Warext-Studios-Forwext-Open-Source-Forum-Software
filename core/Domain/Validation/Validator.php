<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Validation;

/**
 * @template TInput
 */
interface Validator
{
    /** @param TInput $input */
    public function validate(mixed $input): ValidationResult;
}
