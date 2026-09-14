<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

final readonly class CompiledQuery
{
    /** @param array<string, string|int|float|bool|null> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters = [],
        public bool $requiresTransaction = false,
    ) {
        if (trim($sql) === '') {
            throw new DatabaseException('Compiled SQL query cannot be empty.');
        }

        foreach ($parameters as $name => $_value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new DatabaseException(sprintf('Invalid SQL parameter name "%s".', $name));
            }
        }
    }
}
