<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class DatabaseConnection implements TransactionalQueryExecutor
{
    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function execute(CompiledQuery $query): int
    {
        return $this->statement($query)->rowCount();
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $row = $this->statement($query)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new DatabaseException('Database returned an invalid row shape.');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $rows = $this->statement($query)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new DatabaseException('Database returned an invalid result set.');
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return $this->statement($query)->fetchColumn();
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        $level = $this->transactionDepth;
        $savepoint = 'forwext_sp_' . $level;

        try {
            if ($level === 0) {
                if (!$this->pdo->beginTransaction()) {
                    throw new DatabaseException('Unable to begin database transaction.');
                }
            } elseif ($this->pdo->exec('SAVEPOINT ' . $savepoint) === false) {
                throw new DatabaseException('Unable to create database savepoint.');
            }

            ++$this->transactionDepth;
            $result = $callback($this);
            --$this->transactionDepth;

            if ($level === 0) {
                if (!$this->pdo->commit()) {
                    throw new DatabaseException('Unable to commit database transaction.');
                }
            } elseif ($this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint) === false) {
                throw new DatabaseException('Unable to release database savepoint.');
            }

            return $result;
        } catch (Throwable $throwable) {
            if ($this->transactionDepth > $level) {
                --$this->transactionDepth;
            }

            $this->rollbackLevel($level, $savepoint);

            if ($throwable instanceof DatabaseException) {
                throw $throwable;
            }

            if ($throwable instanceof PDOException) {
                throw new DatabaseException('Database transaction failed.', previous: $throwable);
            }

            throw $throwable;
        }
    }

    private function statement(CompiledQuery $query): PDOStatement
    {
        if ($query->requiresTransaction && !$this->inTransaction()) {
            throw new DatabaseException('This query requires an active transaction.');
        }

        try {
            $statement = $this->pdo->prepare($query->sql);
            if (!$statement instanceof PDOStatement) {
                throw new DatabaseException('Unable to prepare database statement.');
            }

            foreach ($query->parameters as $name => $value) {
                $parameter = ':' . $name;
                if ($value === null) {
                    $statement->bindValue($parameter, null, PDO::PARAM_NULL);
                } elseif (is_bool($value)) {
                    $statement->bindValue($parameter, $value, PDO::PARAM_BOOL);
                } elseif (is_int($value)) {
                    $statement->bindValue($parameter, $value, PDO::PARAM_INT);
                } else {
                    $statement->bindValue($parameter, (string) $value, PDO::PARAM_STR);
                }
            }

            $statement->execute();
            return $statement;
        } catch (PDOException $exception) {
            throw new DatabaseException('Database query execution failed.', previous: $exception);
        }
    }

    private function rollbackLevel(int $level, string $savepoint): void
    {
        try {
            if ($level === 0) {
                if ($this->pdo->inTransaction() && !$this->pdo->rollBack()) {
                    throw new DatabaseException('Unable to roll back database transaction.');
                }
                return;
            }

            if ($this->pdo->inTransaction()) {
                if ($this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint) === false) {
                    throw new DatabaseException('Unable to roll back database savepoint.');
                }
                if ($this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint) === false) {
                    throw new DatabaseException('Unable to release rolled-back database savepoint.');
                }
            }
        } catch (PDOException $rollbackException) {
            throw new DatabaseException('Database rollback failed.', previous: $rollbackException);
        }
    }
}
