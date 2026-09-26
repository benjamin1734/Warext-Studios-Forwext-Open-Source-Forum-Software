<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Password\NativePasswordHasher;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Health\DatabaseConnectivityHealthCheck;
use Forwext\Core\Health\HealthReport;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Health\RuntimeEnvironmentHealthCheck;
use Forwext\Core\Health\WritableDirectoryHealthCheck;
use Forwext\Core\Migration\FileInstalledVersionStore;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MigrationException;
use Forwext\Core\Migration\MigrationRunReport;
use Forwext\Core\Migration\MySqlMigrationHistoryStore;
use Forwext\Core\Migration\SemanticVersion;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRegistry;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretStore;
use Forwext\Core\Ui\Theme\DatabaseThemeRepository;
use Forwext\Core\Ui\Theme\ThemeDefinition;
use Forwext\Core\Ui\Theme\ThemePayload;
use Forwext\Core\Ui\Theme\ThemeRevision;
use Forwext\Core\Ui\Theme\ThemeTemplateCache;
use RuntimeException;

final class InstallationService
{
    private const DATABASE_PASSWORD_SECRET = 'database.password';
    private const SMTP_PASSWORD_SECRET = 'mail.smtp.password';

    private ?HealthReport $lastHealthReport = null;
    private ?string $lastAdministratorUserId = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->installedVersionPath());
    }

    public function lastHealthReport(): ?HealthReport
    {
        return $this->lastHealthReport;
    }

    public function lastAdministratorUserId(): ?string
    {
        return $this->lastAdministratorUserId;
    }

    public function install(InstallationInput $input): MigrationRunReport
    {
        $this->assertProjectLayout();
        (new InstallationPreflight($this->projectRoot))->assertReady();

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

            $secrets = $this->secretStore();
            $secrets->set(self::DATABASE_PASSWORD_SECRET, $input->databasePassword);
            if ($input->mailDriver === 'smtp' && $input->smtpPassword !== '') {
                $secrets->set(self::SMTP_PASSWORD_SECRET, $input->smtpPassword);
            } else {
                $secrets->delete(self::SMTP_PASSWORD_SECRET);
            }

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

            $this->assertDatabaseIsFreshOrRecoverable($database, $input);
            $this->writeGeneratedConfig($input);

            $versions = new FileInstalledVersionStore($this->installedVersionPath());
            if ($versions->current() !== null) {
                throw new MigrationException('Forwext is already marked as installed.');
            }

            $report = (new MigrationEngine(
                $database,
                new MySqlMigrationHistoryStore($database),
            ))->migrate(CoreMigrationRegistry::all());

            $administratorId = $this->bootstrapAdministrator($database, $input);
            $this->lastAdministratorUserId = $administratorId;
            $this->configureModules($database, $input, $administratorId);
            $this->seedInstallerTheme($database, $input, $administratorId);

            $health = $this->postInstallHealth($database);
            $this->lastHealthReport = $health;
            if ($health->status === HealthStatus::Unhealthy) {
                throw new RuntimeException('Post-install health verification failed. Installation was not locked as complete.');
            }

            $versions->write($targetVersion);

            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function secretStore(): SecretStore
    {
        $keyProvider = new EnvironmentOrFileSecretKeyProvider(
            $this->projectRoot . '/config/secret.key',
            'FORWEXT_MASTER_KEY',
        );
        $key = $keyProvider->initializeFileIfMissing();

        return new EncryptedFileSecretStore(
            $this->projectRoot . '/storage/secrets/forwext.secrets',
            new SecretCipher($key),
        );
    }

    private function assertDatabaseIsFreshOrRecoverable(
        DatabaseConnection $database,
        InstallationInput $input,
    ): void {
        $usersTable = (int) $database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_users'",
        ));
        if ($usersTable === 0) {
            return;
        }

        $count = (int) $database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_users',
        ));
        if ($count === 0) {
            return;
        }

        if (!$input->hasAdministratorBootstrap() || !$this->isRecoverableBootstrapAdministrator($database, $input)) {
            throw new RuntimeException(
                'The selected database already contains Forwext users and is not a recoverable interrupted installer state.',
            );
        }
    }

    private function isRecoverableBootstrapAdministrator(
        DatabaseConnection $database,
        InstallationInput $input,
    ): bool {
        $requiredTables = (int) $database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_user_credentials','forwext_permission_global_rules')",
        ));
        if ($requiredTables !== 2) {
            return false;
        }

        $username = Username::fromString($input->adminUsername);
        $email = EmailAddress::fromString($input->adminEmail);
        $row = $database->fetchOne(new CompiledQuery(
            'SELECT u.user_id FROM forwext_users u '
            . 'INNER JOIN forwext_user_credentials c ON c.user_id=u.user_id '
            . "INNER JOIN forwext_permission_global_rules p ON p.subject_type='user' "
            . "AND p.subject_id=u.user_id AND p.permission_key='acp.access' AND p.effect='allow' "
            . 'WHERE u.username_key=:username_key AND u.email_key=:email_key LIMIT 1',
            ['username_key'=>$username->key(),'email_key'=>$email->key()],
        ));

        return $row !== null && is_string($row['user_id'] ?? null);
    }

    private function bootstrapAdministrator(
        DatabaseConnection $database,
        InstallationInput $input,
    ): ?string {
        if (!$input->hasAdministratorBootstrap()) {
            return null;
        }

        $username = Username::fromString($input->adminUsername);
        $email = EmailAddress::fromString($input->adminEmail);
        $locale = UserLocale::fromString($input->siteLocale);
        $timezone = UserTimezone::fromString($input->siteTimezone);

        $existing = $database->fetchOne(new CompiledQuery(
            'SELECT user_id,status FROM forwext_users '
            . 'WHERE username_key=:username_key AND email_key=:email_key LIMIT 1',
            ['username_key'=>$username->key(),'email_key'=>$email->key()],
        ));
        if ($existing !== null) {
            $userId = (string) ($existing['user_id'] ?? '');
            if ($userId === '' || (string) ($existing['status'] ?? '') !== UserStatus::Active->value) {
                throw new RuntimeException('Interrupted installer administrator record is invalid.');
            }
            $allowed = (int) $database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_permission_global_rules "
                . "WHERE subject_type='user' AND subject_id=:user_id "
                . "AND permission_key='acp.access' AND effect='allow'",
                ['user_id'=>$userId],
            ));
            if ($allowed !== 1) {
                throw new RuntimeException('Existing installer administrator does not have the expected bootstrap authorization.');
            }

            return $userId;
        }

        $anyUsers = (int) $database->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM forwext_users'));
        if ($anyUsers !== 0) {
            throw new RuntimeException('Administrator bootstrap requires an empty Forwext user table.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $time = self::format($now);
        $userId = UserId::generate()->value();
        $passwordHash = (new NativePasswordHasher())->hash($input->adminPassword);

        $database->transaction(function (DatabaseConnection $database) use (
            $username,
            $email,
            $locale,
            $timezone,
            $time,
            $userId,
            $passwordHash,
        ): void {
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_users '
                . '(user_id,username,username_key,email,email_key,status,locale,timezone,version,created_at_utc,updated_at_utc) '
                . 'VALUES (:user_id,:username,:username_key,:email,:email_key,:status,:locale,:timezone,1,:created_at,:updated_at)',
                [
                    'user_id'=>$userId,
                    'username'=>$username->display(),
                    'username_key'=>$username->key(),
                    'email'=>$email->value(),
                    'email_key'=>$email->key(),
                    'status'=>UserStatus::Active->value,
                    'locale'=>$locale->value(),
                    'timezone'=>$timezone->value(),
                    'created_at'=>$time,
                    'updated_at'=>$time,
                ],
            ));
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_history '
                . '(user_id,event_type,changed_fields_json,occurred_at_utc,actor_user_id,from_status,to_status,reason_code) '
                . "VALUES (:user_id,'user.created',:fields,:occurred_at,NULL,NULL,:to_status,'install.bootstrap')",
                [
                    'user_id'=>$userId,
                    'fields'=>'["username","email","status","locale","timezone"]',
                    'occurred_at'=>$time,
                    'to_status'=>UserStatus::Active->value,
                ],
            ));
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_credentials '
                . '(user_id,password_hash,credential_version,password_changed_at_utc) '
                . 'VALUES (:user_id,:password_hash,1,:changed_at)',
                ['user_id'=>$userId,'password_hash'=>$passwordHash,'changed_at'=>$time],
            ));

            $groupId = bin2hex(random_bytes(16));
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_groups '
                . '(group_id,group_key,name,is_system,sort_order,created_at_utc,updated_at_utc) '
                . "VALUES (:group_id,'registered','Registered',1,20,:created_at,:updated_at) "
                . 'ON DUPLICATE KEY UPDATE group_key=VALUES(group_key)',
                ['group_id'=>$groupId,'created_at'=>$time,'updated_at'=>$time],
            ));
            $storedGroupId = $database->fetchValue(new CompiledQuery(
                "SELECT group_id FROM forwext_user_groups WHERE group_key='registered' LIMIT 1",
            ));
            if (!is_string($storedGroupId) || $storedGroupId === '') {
                throw new RuntimeException('Unable to resolve installer primary group.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_primary_groups (user_id,group_id,assigned_at_utc) '
                . 'VALUES (:user_id,:group_id,:assigned_at)',
                ['user_id'=>$userId,'group_id'=>$storedGroupId,'assigned_at'=>$time],
            ));

            $roleId = bin2hex(random_bytes(16));
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_roles '
                . '(role_id,role_key,name,kind,is_protected,priority,created_at_utc,updated_at_utc) '
                . "VALUES (:role_id,'administrator','Administrator','staff',1,1000,:created_at,:updated_at) "
                . 'ON DUPLICATE KEY UPDATE role_key=VALUES(role_key)',
                ['role_id'=>$roleId,'created_at'=>$time,'updated_at'=>$time],
            ));
            $storedRoleId = $database->fetchValue(new CompiledQuery(
                "SELECT role_id FROM forwext_roles WHERE role_key='administrator' LIMIT 1",
            ));
            if (!is_string($storedRoleId) || $storedRoleId === '') {
                throw new RuntimeException('Unable to resolve installer administrator role.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_role_assignments (user_id,role_id,assigned_at_utc) '
                . 'VALUES (:user_id,:role_id,:assigned_at)',
                ['user_id'=>$userId,'role_id'=>$storedRoleId,'assigned_at'=>$time],
            ));

            $templateRules = (int) $database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key='administrator'",
            ));
            if ($templateRules < 1) {
                throw new RuntimeException('Administrator permission template is unavailable.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_global_rules '
                . '(subject_type,subject_id,permission_key,effect,numeric_limit,updated_at_utc) '
                . "SELECT 'user',:user_id,permission_key,effect,numeric_limit,:updated_at "
                . "FROM forwext_permission_template_rules WHERE template_key='administrator' "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=VALUES(numeric_limit),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['user_id'=>$userId,'updated_at'=>$time],
            ));

            $this->recordInstallAudit(
                $database,
                $userId,
                'install.administrator.bootstrap',
                'user.account',
                $userId,
                ['username'=>$username->display(),'role'=>'administrator'],
                $time,
            );
        });

        return $userId;
    }

    private function configureModules(
        DatabaseConnection $database,
        InstallationInput $input,
        ?string $administratorId,
    ): void {
        if ($input->enabledModules === null) {
            return;
        }

        $registry = FirstPartyModuleRegistry::withCoreDefaults();
        $selected = array_fill_keys($input->enabledModules, true);
        foreach ($input->enabledModules as $moduleKey) {
            $definition = $registry->find($moduleKey);
            if ($definition === null) {
                throw new RuntimeException('Unknown installer module selection: ' . $moduleKey);
            }
            foreach ($definition->dependencies as $dependency) {
                if (!isset($selected[$dependency])) {
                    throw new RuntimeException(sprintf(
                        'Module "%s" requires "%s" during installation.',
                        $moduleKey,
                        $dependency,
                    ));
                }
            }
            foreach ($definition->conflicts as $conflict) {
                if (isset($selected[$conflict])) {
                    throw new RuntimeException(sprintf(
                        'Modules "%s" and "%s" cannot both be enabled.',
                        $moduleKey,
                        $conflict,
                    ));
                }
            }
        }

        $time = self::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $database->transaction(function (DatabaseConnection $database) use (
            $registry,
            $selected,
            $administratorId,
            $time,
        ): void {
            foreach ($registry->all() as $definition) {
                $state = isset($selected[$definition->key]) ? 'enabled' : 'disabled';
                $affected = $database->execute(new CompiledQuery(
                    'UPDATE forwext_first_party_modules '
                    . 'SET state=:state,updated_by_user_id=:actor,updated_at_utc=:updated_at '
                    . 'WHERE module_key=:module_key',
                    [
                        'state'=>$state,
                        'actor'=>$administratorId,
                        'updated_at'=>$time,
                        'module_key'=>$definition->key,
                    ],
                ));
                if ($affected !== 1) {
                    throw new RuntimeException('Installer module state row is missing: ' . $definition->key);
                }
            }

            if ($administratorId !== null) {
                $this->recordInstallAudit(
                    $database,
                    $administratorId,
                    'install.modules.configure',
                    'module.selection',
                    'initial',
                    ['enabled'=>array_keys($selected)],
                    $time,
                );
            }
        });
    }

    private function seedInstallerTheme(
        DatabaseConnection $database,
        InstallationInput $input,
        ?string $administratorId,
    ): void {
        if ($administratorId === null) {
            return;
        }

        $repository = new DatabaseThemeRepository($database);
        $key = $input->themeKey();
        $existing = $repository->findByKey($key);
        if ($existing !== null && $existing->publishedRevisionId !== null) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $actor = \Forwext\Core\Domain\Entity\EntityId::fromString($administratorId);
        $theme = new ThemeDefinition(
            $existing?->themeId ?? ThemeDefinition::generateId(),
            $key,
            $input->themeName(),
            null,
            $existing?->stagingRevisionId,
            $existing?->publishedRevisionId,
            $existing?->createdAt ?? $now,
            $now,
        );
        $payload = new ThemePayload(
            [],
            [
                'tr' => ['theme.installer.name' => $input->themeName()],
                'en' => ['theme.installer.name' => $input->themeName()],
            ],
        );
        $revision = new ThemeRevision(
            ThemeRevision::generateId(),
            $theme->themeId,
            $payload,
            $actor,
            $now,
        );

        $database->transaction(function () use ($repository, $theme, $revision, $actor, $now): void {
            $repository->saveStaging($theme, $revision, $actor, $now);
            if (!$repository->publish($theme->themeId, $revision->revisionId, $actor, $now)) {
                throw new RuntimeException('Installer theme publish failed.');
            }
        });
        (new ThemeTemplateCache($this->projectRoot . '/storage/cache/themes'))
            ->compile($key, $revision);

        $this->recordInstallAudit(
            $database,
            $administratorId,
            'install.theme.publish',
            'ui.theme',
            $theme->themeId->value(),
            ['theme_key'=>$key,'revision_id'=>$revision->revisionId->value()],
            self::format($now),
        );
    }

    private function postInstallHealth(DatabaseConnection $database): HealthReport
    {
        return (new HealthService([
            new RuntimeEnvironmentHealthCheck(),
            new DatabaseConnectivityHealthCheck($database),
            new WritableDirectoryHealthCheck('config_writable', $this->projectRoot . '/config'),
            new WritableDirectoryHealthCheck('storage_writable', $this->projectRoot . '/storage'),
            new WritableDirectoryHealthCheck('secrets_writable', $this->projectRoot . '/storage/secrets'),
        ]))->report();
    }

    /** @param array<string,mixed> $after */
    private function recordInstallAudit(
        DatabaseConnection $database,
        string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        array $after,
        string $time,
    ): void {
        $database->execute(new CompiledQuery(
            'INSERT INTO forwext_core_audit_events '
            . '(audit_id,scope,actor_user_id,action,target_type,target_id,forum_node_id,reason_code,request_id,'
            . 'before_json,after_json,occurred_at_utc) '
            . "VALUES (:audit_id,'administration',:actor,:action,:target_type,:target_id,NULL,'install.bootstrap',"
            . ':request_id,:before_json,:after_json,:occurred_at)',
            [
                'audit_id'=>bin2hex(random_bytes(16)),
                'actor'=>$actorUserId,
                'action'=>$action,
                'target_type'=>$targetType,
                'target_id'=>$targetId,
                'request_id'=>'install-' . bin2hex(random_bytes(12)),
                'before_json'=>'{}',
                'after_json'=>json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'occurred_at'=>$time,
            ],
        ));
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
            'site' => [
                'name' => $input->siteName,
                'description' => $input->siteDescription,
                'default_locale' => UserLocale::fromString($input->siteLocale)->value(),
                'timezone' => UserTimezone::fromString($input->siteTimezone)->value(),
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
            'mail' => [
                'driver' => $input->mailDriver,
                'from_address' => $input->mailFromAddress === '' ? null : EmailAddress::fromString($input->mailFromAddress)->value(),
                'from_name' => $input->mailFromName,
                'smtp' => [
                    'host' => $input->smtpHost === '' ? null : $input->smtpHost,
                    'port' => $input->smtpPort,
                    'encryption' => $input->smtpEncryption,
                    'username' => $input->smtpUsername === '' ? null : $input->smtpUsername,
                    'password_secret' => self::SMTP_PASSWORD_SECRET,
                ],
            ],
            'appearance' => [
                'default_theme_key' => $input->themeKey(),
                'installer_preset' => $input->themePreset,
            ],
            'scheduler' => [
                'timezone' => UserTimezone::fromString($input->siteTimezone)->value(),
            ],
            'http_security' => [
                'trusted_hosts' => [$input->relyingPartyId()],
            ],
            'mfa' => [
                'webauthn' => [
                    'rp_name' => $input->siteName,
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

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
