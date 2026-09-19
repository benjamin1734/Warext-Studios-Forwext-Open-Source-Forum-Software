<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Migration\FileInstalledVersionStore;
use Forwext\Core\Migration\InstallUpgradeEngine;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MigrationException;
use Forwext\Core\Migration\MigrationRunReport;
use Forwext\Core\Migration\MySqlMigrationHistoryStore;
use Forwext\Core\Migration\SemanticVersion;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use RuntimeException;

final class InstallationService
{
    private const DATABASE_PASSWORD_SECRET = 'database.password';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->installedVersionPath());
    }

    public function install(InstallationInput $input): MigrationRunReport
    {
        $this->assertProjectLayout();
        $targetVersion = $this->projectVersion();
        $lockPath = $this->projectRoot . '/storage/install.lock';
        $this->ensureDirectory(dirname($lockPath));
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the installation lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the installation lock.');
            }
            if ($this->isInstalled()) {
                throw new MigrationException('Forwext is already installed.');
            }

            (new ApacheHtaccessManager($this->projectRoot . '/.htaccess'))->ensurePublicRouting();
            (new ApacheHtaccessManager($this->projectRoot . '/public/.htaccess'))->ensurePublicRouting();

            $keyProvider = new EnvironmentOrFileSecretKeyProvider(
                $this->projectRoot . '/config/secret.key',
                'FORWEXT_MASTER_KEY',
            );
            $key = $keyProvider->initializeFileIfMissing();
            $secrets = new EncryptedFileSecretStore(
                $this->projectRoot . '/storage/secrets/forwext.secrets',
                new SecretCipher($key),
            );
            $secrets->set(self::DATABASE_PASSWORD_SECRET, $input->databasePassword);

            $database = (new PdoConnectionFactory())->create(new DatabaseConfig(
                $input->databaseHost,
                $input->databasePort,
                $input->databaseName,
                $input->databaseUsername,
                $input->databasePassword,
            ));
            if ((int) $database->fetchValue(new CompiledQuery('SELECT 1')) !== 1) {
                throw new RuntimeException('Database connectivity check failed.');
            }

            $this->writeGeneratedConfig($input);

            $versions = new FileInstalledVersionStore($this->installedVersionPath());
            $engine = new InstallUpgradeEngine(
                new MigrationEngine($database, new MySqlMigrationHistoryStore($database)),
                $versions,
            );
            return $engine->install($targetVersion, CoreMigrationRegistry::all());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeGeneratedConfig(InstallationInput $input): void
    {
        $path = $this->projectRoot . '/config/generated.php';
        if (is_link($path)) {
            throw new RuntimeException('Generated configuration may not be a symbolic link.');
        }

        $configuration = [
            'app' => [
                'environment' => 'production',
                'debug' => false,
                'maintenance' => false,
            ],
            'routing' => [
                'canonical_url' => $input->normalizedCanonicalUrl(),
            ],
            'database' => [
                'driver' => 'mysql',
                'host' => $input->databaseHost,
                'port' => $input->databasePort,
                'name' => $input->databaseName,
                'username' => $input->databaseUsername,
                'charset' => 'utf8mb4',
                'connect_timeout_seconds' => 5,
                'unix_socket' => null,
                'password_secret' => self::DATABASE_PASSWORD_SECRET,
            ],
            'mfa' => [
                'webauthn' => [
                    'rp_name' => 'Forwext',
                    'rp_id' => $input->relyingPartyId(),
                    'host' => $input->normalizedCanonicalUrl(),
                    'ceremony_ttl_seconds' => 300,
                    'user_verification' => 'required',
                    'attestation' => 'none',
                ],
            ],
        ];

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($configuration, true)
            . ";\n";
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new RuntimeException('Unable to stage generated configuration.');
            }
            if (!@chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to restrict generated configuration permissions.');
            }
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Unable to activate generated configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function installedVersionPath(): string
    {
        return $this->projectRoot . '/storage/install/installed-version.json';
    }

    private function projectVersion(): SemanticVersion
    {
        $contents = @file_get_contents($this->projectRoot . '/VERSION');
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the Forwext VERSION file.');
        }

        return SemanticVersion::parse(trim($contents));
    }

    private function assertProjectLayout(): void
    {
        foreach (['VERSION', 'vendor/autoload.php', 'config', 'storage'] as $relative) {
            if (!file_exists($this->projectRoot . '/' . $relative)) {
                throw new RuntimeException(sprintf('Required installation resource "%s" is missing.', $relative));
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the installation state directory.');
        }
    }
}
