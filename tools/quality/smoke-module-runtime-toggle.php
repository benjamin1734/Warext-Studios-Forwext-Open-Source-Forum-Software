<?php

declare(strict_types=1);

use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Migration\FileInstalledVersionStore;
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

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3306'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_ci'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$record = $database->fetchOne(new CompiledQuery(
    'SELECT module_key,state FROM forwext_first_party_modules WHERE module_key=:module_key LIMIT 1',
    ['module_key'=>'marketplace'],
));
if ($record === null) {
    throw new RuntimeException('Marketplace module seed is missing after installation.');
}

$originalState = (string) ($record['state'] ?? '');
if ($originalState !== 'enabled') {
    throw new RuntimeException('Marketplace module must start enabled for the runtime-toggle smoke.');
}

$version = (new FileInstalledVersionStore(
    $root . '/storage/install/installed-version.json',
))->current();
if ($version === null) {
    throw new RuntimeException('Installed version is missing before module runtime-toggle smoke.');
}

$_SERVER['SCRIPT_NAME'] = '/index.php';

$setState = static function (string $state) use ($database): void {
    $changed = $database->execute(new CompiledQuery(
        'UPDATE forwext_first_party_modules '
        . 'SET state=:state,updated_by_user_id=NULL,updated_at_utc=UTC_TIMESTAMP(6) '
        . 'WHERE module_key=:module_key',
        ['state'=>$state,'module_key'=>'marketplace'],
    ));
    if ($changed !== 1) {
        throw new RuntimeException(sprintf('Marketplace module state update to %s changed %d rows.', $state, $changed));
    }
};

$requestMarketplace = static function () use ($root, $version) {
    return (new WebApplicationFactory($root))
        ->create($version->value())
        ->handle(new Request(HttpMethod::Get, '/marketplace'));
};

try {
    $setState('disabled');
    $disabled = $requestMarketplace();
    if ($disabled->status() !== 503) {
        throw new RuntimeException(sprintf(
            'Disabled Marketplace route returned HTTP %d instead of 503.',
            $disabled->status(),
        ));
    }

    $setState('enabled');
    $enabled = $requestMarketplace();
    if ($enabled->status() === 404 || $enabled->status() === 503 || $enabled->status() >= 500) {
        throw new RuntimeException(sprintf(
            'Re-enabled Marketplace route is unusable (HTTP %d).',
            $enabled->status(),
        ));
    }

    printf(
        "Installed module ON/OFF smoke passed: disabled=503 enabled=%d.\n",
        $enabled->status(),
    );
} finally {
    $database->execute(new CompiledQuery(
        'UPDATE forwext_first_party_modules '
        . 'SET state=:state,updated_by_user_id=NULL,updated_at_utc=UTC_TIMESTAMP(6) '
        . 'WHERE module_key=:module_key',
        ['state'=>$originalState,'module_key'=>'marketplace'],
    ));
}
