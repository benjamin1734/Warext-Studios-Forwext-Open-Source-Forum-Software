<?php

declare(strict_types=1);

use Forwext\App\Web\Community\CommunityApplicationFactory;
use Forwext\App\Web\Moderation\ModerationApplicationFactory;
use Forwext\App\Web\Report\ReportApplicationFactory;
use Forwext\App\Web\Seo\SeoApplicationFactory;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Install\InstallationInput;
use Forwext\Core\Install\InstallationService;
use Forwext\Core\Migration\FileInstalledVersionStore;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRegistry;

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

$moduleKeys = array_map(
    static fn ($definition): string => $definition->key,
    FirstPartyModuleRegistry::withCoreDefaults()->all(),
);
$databaseHost = $env('FORWEXT_TEST_DB_HOST', '127.0.0.1');
$databasePort = (int) $env('FORWEXT_TEST_DB_PORT', '3306');
$databaseName = $env('FORWEXT_TEST_DB_NAME', 'forwext_ci');
$databaseUser = $env('FORWEXT_TEST_DB_USER', 'root');
$databasePassword = $env('FORWEXT_TEST_DB_PASSWORD', 'root');

$installer = new InstallationService($root);
$report = $installer->install(new InstallationInput(
    'https://forwext.test',
    $databaseHost,
    $databasePort,
    $databaseName,
    $databaseUser,
    $databasePassword,
    siteName: 'Forwext CI Forum',
    siteDescription: 'Installer integration smoke.',
    siteLocale: 'tr',
    siteTimezone: 'Europe/Istanbul',
    adminUsername: 'ci-admin',
    adminEmail: 'ci-admin@forwext.test',
    adminPassword: 'Forwext-CI-Admin-Password-2026',
    mailDriver: 'disabled',
    mailFromName: 'Forwext CI',
    enabledModules: $moduleKeys,
    themePreset: 'balanced',
));

$version = (new FileInstalledVersionStore(
    $root . '/storage/install/installed-version.json',
))->current();
if ($version === null) {
    throw new RuntimeException('Installer did not persist an installed version.');
}

$health = $installer->lastHealthReport();
if ($health === null || $health->status !== HealthStatus::Healthy) {
    throw new RuntimeException('Installer post-install health report is not healthy.');
}
$administratorId = $installer->lastAdministratorUserId();
if (!is_string($administratorId) || $administratorId === '') {
    throw new RuntimeException('Installer did not create the bootstrap administrator.');
}

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $databaseHost,
    $databasePort,
    $databaseName,
    $databaseUser,
    $databasePassword,
));
$admin = $database->fetchOne(new CompiledQuery(
    'SELECT u.user_id,u.status,c.credential_version FROM forwext_users u '
    . 'INNER JOIN forwext_user_credentials c ON c.user_id=u.user_id '
    . 'WHERE u.user_id=:user_id LIMIT 1',
    ['user_id'=>$administratorId],
));
if ($admin === null || (string) $admin['status'] !== 'active' || (int) $admin['credential_version'] !== 1) {
    throw new RuntimeException('Installer administrator identity/credential bootstrap is incomplete.');
}
$adminPermissions = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_permission_global_rules "
    . "WHERE subject_type='user' AND subject_id=:user_id "
    . "AND permission_key IN ('acp.access','acp.manage','module.manage','system.health.view') "
    . "AND effect='allow'",
    ['user_id'=>$administratorId],
));
if ($adminPermissions !== 4) {
    throw new RuntimeException('Installer administrator did not receive current administrator permissions.');
}
$enabledModules = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_first_party_modules WHERE state='enabled'",
));
if ($enabledModules !== count($moduleKeys)) {
    throw new RuntimeException('Installer module selection was not persisted.');
}
$theme = $database->fetchOne(new CompiledQuery(
    "SELECT theme_key,published_revision_id FROM forwext_themes WHERE theme_key='forwext-balanced' LIMIT 1",
));
if ($theme === null || !is_string($theme['published_revision_id'] ?? null) || $theme['published_revision_id'] === '') {
    throw new RuntimeException('Installer did not publish the selected initial theme.');
}

$generated = require $root . '/config/generated.php';
if (!is_array($generated)
    || ($generated['site']['name'] ?? null) !== 'Forwext CI Forum'
    || ($generated['site']['timezone'] ?? null) !== 'Europe/Istanbul'
    || ($generated['appearance']['default_theme_key'] ?? null) !== 'forwext-balanced'
    || ($generated['database']['password_secret'] ?? null) !== 'database.password'
    || array_key_exists('password', $generated['database'] ?? [])
) {
    throw new RuntimeException('Installer generated configuration is incomplete or contains an unsafe database password field.');
}

$handle = static function (string $requestPath) use ($root, $version) {
    $request = new Request(HttpMethod::Get, $requestPath);

    $seo = new SeoApplicationFactory($root);
    $response = $seo->handle($request);

    if ($response === null) {
        $response = (new ModerationApplicationFactory($root))->handle($request);
    }
    if ($response === null) {
        $response = (new ReportApplicationFactory($root))->handle($request);
    }
    if ($response === null) {
        $response = (new CommunityApplicationFactory($root))->handle($request);
    }

    $web = new WebApplicationFactory($root);
    if ($response === null) {
        $response = $web->create($version->value())->handle($request);
    }

    return $seo->decorate($request, $response)
        ->withHeader('Content-Security-Policy', $web->contentSecurityPolicy());
};

$_SERVER['SCRIPT_NAME'] = '/index.php';
$rootResponse = $handle('/');
if ($rootResponse->status() !== 200) {
    throw new RuntimeException(sprintf(
        'Post-install home bootstrap returned HTTP %d instead of 200.',
        $rootResponse->status(),
    ));
}
if (!str_contains($rootResponse->body(), 'Forwext Forum Platform')) {
    throw new RuntimeException('Post-install home response did not contain the expected Forwext marker.');
}
if ($rootResponse->headers()->first('Content-Security-Policy') === null) {
    throw new RuntimeException('Post-install home response is missing Content-Security-Policy.');
}

$navigationRoutes = [
    '/search',
    '/members',
    '/members/online',
    '/portfolio',
    '/giveaways',
    '/marketplace',
    '/faq',
    '/account/referrals',
    '/account/upgrades',
    '/bugs',
    '/stats',
];

foreach ($navigationRoutes as $route) {
    $response = $handle($route);
    if ($response->status() === 404 || $response->status() >= 500) {
        throw new RuntimeException(sprintf(
            'Post-install navigation route %s is not usable (HTTP %d).',
            $route,
            $response->status(),
        ));
    }
}

$_SERVER['SCRIPT_NAME'] = '/public/index.php';
foreach (array_merge(['/'], $navigationRoutes) as $route) {
    $requestPath = '/public' . $route;
    $response = $handle($requestPath);
    if ($response->status() === 404 || $response->status() >= 500) {
        throw new RuntimeException(sprintf(
            'Subfolder deployment route %s is not usable (HTTP %d).',
            $requestPath,
            $response->status(),
        ));
    }
}

printf(
    "Post-install web bootstrap smoke passed: version=%s migrations_applied=%d migrations_skipped=%d "
    . "administrator=ok modules=%d theme=ok health=%s all_navigation_and_subfolder_routes=ok.\n",
    $version->value(),
    count($report->applied),
    count($report->skipped),
    $enabledModules,
    $health->status->value,
);
