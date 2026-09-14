<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Validation;

/**
 * @template TInput
 * @implements Validator<TInput>
 */
final readonly class CompositeValidator implements Validator
{
    /** @var list<Validator<TInput>> */
    private array $validators;

    /** @param iterable<Validator<TInput>> $validators */
    public function __construct(iterable $validators)
    {
        $normalized = [];
        foreach ($validators as $validator) {
            $normalized[] = $validator;
        }

        $this->validators = $normalized;
    }

    public function validate(mixed $input): ValidationResult
    {
        $result = ValidationResult::valid();

        foreach ($this->validators as $validator) {
            $result = $result->merged($validator->validate($input));
        }

        return $result;
    }
}
