<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Migration\MigrationContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MigrationSchemaContractTest extends TestCase
{
    public function testEveryCoreForeignKeyTargetsAnExistingEarlierColumn(): void
    {
        /** @var array<string, array<string, true>> $schema */
        $schema = [];
        $violations = [];
        $migrations = CoreMigrationRegistry::all();
        usort($migrations, static fn ($left, $right): int => $left->id()->value() <=> $right->id()->value());

        foreach ($migrations as $migration) {
            $recorder = new MigrationSchemaSqlRecorder();
            $migration->up(new MigrationContext($recorder));

            foreach ($recorder->statements as $sql) {
                $currentTable = null;
                if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*)\) ENGINE=/s', $sql, $create) === 1) {
                    $currentTable = $create[1];
                    $schema[$currentTable] ??= [];
                    foreach (self::splitTopLevelSql($create[2]) as $definition) {
                        if (preg_match('/^`([^`]+)`\s+/', $definition, $column) === 1) {
                            $schema[$currentTable][$column[1]] = true;
                        }
                    }
                } elseif (preg_match('/ALTER TABLE `([^`]+)`/', $sql, $alter) === 1) {
                    $currentTable = $alter[1];
                    if (preg_match_all('/ADD COLUMN `([^`]+)`\s+/', $sql, $columns) > 0) {
                        foreach ($columns[1] as $column) {
                            $schema[$currentTable][(string) $column] = true;
                        }
                    }
                }

                if (preg_match_all(
                    '/FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)/',
                    $sql,
                    $foreignKeys,
                    PREG_SET_ORDER,
                ) === 0) {
                    continue;
                }

                foreach ($foreignKeys as $foreignKey) {
                    [, $localColumn, $targetTable, $targetColumn] = $foreignKey;
                    if ($currentTable === null || !isset($schema[$currentTable][$localColumn])) {
                        $violations[] = sprintf(
                            '%s: local FK column %s.%s is missing.',
                            $migration->id()->value(),
                            $currentTable ?? '<unknown>',
                            $localColumn,
                        );
                    }
                    if (!isset($schema[$targetTable])) {
                        $violations[] = sprintf(
                            '%s: FK target table %s is not created before use.',
                            $migration->id()->value(),
                            $targetTable,
                        );
                        continue;
                    }
                    if (!isset($schema[$targetTable][$targetColumn])) {
                        $violations[] = sprintf(
                            '%s: FK target column %s.%s does not exist.',
                            $migration->id()->value(),
                            $targetTable,
                            $targetColumn,
                        );
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
        self::assertGreaterThanOrEqual(90, count($schema));
    }

    public function testProductionCodeDoesNotReferenceLegacyUserIdColumn(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];
        foreach (['core', 'app', 'database'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root . '/' . $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    continue;
                }
                if (
                    str_contains($source, 'REFERENCES `forwext_users` (`id`)')
                    || str_contains($source, 'SELECT `id` FROM `forwext_users`')
                    || str_contains($source, 'FROM `forwext_users` WHERE `id`')
                ) {
                    $violations[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $violations, 'Legacy forwext_users.id references: ' . implode(', ', $violations));
    }

    /** @return list<string> */
    private static function splitTopLevelSql(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($body);

        for ($index = 0; $index < $length; ++$index) {
            $character = $body[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($character === '\\' && $quote !== '`') {
                    $escaped = true;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')') {
                --$depth;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}

final class MigrationSchemaSqlRecorder implements TransactionalQueryExecutor
{
    /** @var list<string> */
    public array $statements = [];

    public function execute(CompiledQuery $query): int
    {
        $this->statements[] = $query->sql;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->statements[] = $query->sql;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->statements[] = $query->sql;
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->statements[] = $query->sql;
        return 0;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
