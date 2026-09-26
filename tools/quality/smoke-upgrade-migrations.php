<?php

declare(strict_types=1);

use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MySqlMigrationHistoryStore;
use PDO;
use RuntimeException;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

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

$host = $env('FORWEXT_TEST_DB_HOST', '127.0.0.1');
$port = (int) $env('FORWEXT_TEST_DB_PORT', '3306');
$baseName = $env('FORWEXT_TEST_DB_NAME', 'forwext_ci');
$user = $env('FORWEXT_TEST_DB_USER', 'root');
$password = $env('FORWEXT_TEST_DB_PASSWORD', 'root');
$databaseName = $baseName . '_upgrade';

if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $databaseName) !== 1) {
    throw new RuntimeException('Upgrade smoke database name is invalid.');
}

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ],
);
$quotedDatabase = '`' . $databaseName . '`';
$server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$server->exec(
    'CREATE DATABASE ' . $quotedDatabase
    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
);

try {
    $database = (new PdoConnectionFactory())->create(new DatabaseConfig(
        $host,
        $port,
        $databaseName,
        $user,
        $password,
    ));
    $migrations = CoreMigrationRegistry::all();
    if (count($migrations) < 2) {
        throw new RuntimeException('Upgrade smoke requires at least two registered migrations.');
    }

    $previousReleaseMigrations = array_slice($migrations, 0, -1);
    $engine = new MigrationEngine($database, new MySqlMigrationHistoryStore($database));

    $baseline = $engine->migrate($previousReleaseMigrations);
    if (count($baseline->applied) !== count($previousReleaseMigrations)) {
        throw new RuntimeException(sprintf(
            'Upgrade baseline applied %d of %d migrations.',
            count($baseline->applied),
            count($previousReleaseMigrations),
        ));
    }

    $upgrade = $engine->migrate($migrations);
    if (count($upgrade->applied) !== 1
        || count($upgrade->skipped) !== count($previousReleaseMigrations)
    ) {
        throw new RuntimeException(sprintf(
            'Upgrade pass expected 1 applied and %d skipped migrations; got %d applied and %d skipped.',
            count($previousReleaseMigrations),
            count($upgrade->applied),
            count($upgrade->skipped),
        ));
    }

    $idempotent = $engine->migrate($migrations);
    if ($idempotent->applied !== [] || count($idempotent->skipped) !== count($migrations)) {
        throw new RuntimeException('Post-upgrade migration pass was not idempotent.');
    }

    printf(
        "Migration upgrade smoke passed: baseline=%d upgrade_applied=1 final=%d.\n",
        count($previousReleaseMigrations),
        count($migrations),
    );
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
}
