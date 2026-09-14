<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain;

use Forwext\Core\Domain\Validation\CompositeValidator;
use Forwext\Core\Domain\Validation\ValidationException;
use Forwext\Core\Domain\Validation\ValidationResult;
use Forwext\Core\Domain\Validation\ValidationViolation;
use Forwext\Core\Domain\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testCompositeValidatorCollectsAllViolations(): void
    {
        $validator = new CompositeValidator([
            new MinimumLengthValidator(3),
            new NoWhitespaceValidator(),
        ]);

        $result = $validator->validate('a b');

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->violations());
        self::assertSame('whitespace_not_allowed', $result->violations()[0]->code);
    }

    public function testThrowIfInvalidKeepsStructuredViolations(): void
    {
        $result = ValidationResult::invalid(
            new ValidationViolation('username', 'too_short', 'Username is too short.', ['min' => 3]),
        );

        try {
            $result->throwIfInvalid();
            self::fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertSame('too_short', $exception->violations()[0]->code);
            self::assertSame(['min' => 3], $exception->violations()[0]->parameters);
        }
    }
}

/** @implements Validator<string> */
final readonly class MinimumLengthValidator implements Validator
{
    public function __construct(private int $minimum)
    {
    }

    public function validate(mixed $input): ValidationResult
    {
        if (!is_string($input) || strlen($input) < $this->minimum) {
            return ValidationResult::invalid(new ValidationViolation(
                'value',
                'too_short',
                'Value is too short.',
                ['min' => $this->minimum],
            ));
        }

        return ValidationResult::valid();
    }
}

/** @implements Validator<string> */
final readonly class NoWhitespaceValidator implements Validator
{
    public function validate(mixed $input): ValidationResult
    {
        if (!is_string($input) || preg_match('/\s/u', $input) === 1) {
            return ValidationResult::invalid(new ValidationViolation(
                'value',
                'whitespace_not_allowed',
                'Whitespace is not allowed.',
            ));
        }

        return ValidationResult::valid();
    }
}
