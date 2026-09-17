<?php

declare(strict_types=1);

use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MySqlMigrationHistoryStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException(sprintf('Required environment variable %s is missing.', $key));
    }
    return $value;
};

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3306'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_ci'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$migrations = CoreMigrationRegistry::all();
$engine = new MigrationEngine($database, new MySqlMigrationHistoryStore($database));
$first = $engine->migrate($migrations);
if (count($first->applied) !== count($migrations)) {
    throw new RuntimeException(sprintf(
        'Clean migration smoke test applied %d of %d migrations.',
        count($first->applied),
        count($migrations),
    ));
}

$second = $engine->migrate($migrations);
if ($second->applied !== [] || count($second->skipped) !== count($migrations)) {
    throw new RuntimeException('Second migration pass was not fully idempotent.');
}

printf("Clean MySQL migration smoke test passed: %d migrations.\n", count($migrations));
