<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Validation;

use InvalidArgumentException;

final readonly class ValidationViolation
{
    /** @param array<string, string|int|float|bool|null> $parameters */
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
        public array $parameters = [],
    ) {
        if ($path === '' || strlen($path) > 191) {
            throw new InvalidArgumentException('Validation violation path must be between 1 and 191 bytes.');
        }

        if ($code === '' || preg_match('/^[A-Za-z0-9._:-]{1,191}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Validation violation code contains unsupported characters or length.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('Validation violation message cannot be empty.');
        }
    }
}
