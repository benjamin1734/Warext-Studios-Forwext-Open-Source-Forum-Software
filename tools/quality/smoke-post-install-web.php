<?php

declare(strict_types=1);

use Forwext\App\Web\Seo\SeoApplicationFactory;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Install\InstallationInput;
use Forwext\Core\Install\InstallationService;
use Forwext\Core\Migration\FileInstalledVersionStore;

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

$generatedFiles = [
    $root . '/config/generated.php',
    $root . '/config/secret.key',
    $root . '/storage/secrets/forwext.secrets',
    $root . '/storage/secrets/forwext.secrets.lock',
    $root . '/storage/install/installed-version.json',
    $root . '/storage/install.lock',
];

foreach ($generatedFiles as $path) {
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException(sprintf('Unable to reset generated smoke-test file %s.', $path));
    }
}

$installer = new InstallationService($root);
$report = $installer->install(new InstallationInput(
    'https://forwext.test',
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3306'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_ci'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$version = (new FileInstalledVersionStore(
    $root . '/storage/install/installed-version.json',
))->current();
if ($version === null) {
    throw new RuntimeException('Installer did not persist an installed version.');
}

$request = new Request(HttpMethod::Get, '/');
$seo = new SeoApplicationFactory($root);
$response = $seo->handle($request);

$web = new WebApplicationFactory($root);
if ($response === null) {
    $response = $web->create($version->value())->handle($request);
}
$response = $seo->decorate($request, $response)
    ->withHeader('Content-Security-Policy', $web->contentSecurityPolicy());

if ($response->status() !== 200) {
    throw new RuntimeException(sprintf(
        'Post-install home bootstrap returned HTTP %d instead of 200.',
        $response->status(),
    ));
}
if (!str_contains($response->body(), 'Forwext Forum Platform')) {
    throw new RuntimeException('Post-install home response did not contain the expected Forwext marker.');
}
if ($response->headers()->first('Content-Security-Policy') === null) {
    throw new RuntimeException('Post-install home response is missing Content-Security-Policy.');
}

printf(
    "Post-install web bootstrap smoke passed: version=%s migrations_applied=%d migrations_skipped=%d.\n",
    $version->value(),
    count($report->applied),
    count($report->skipped),
);
