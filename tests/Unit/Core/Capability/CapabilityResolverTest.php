<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Capability;

use Forwext\Core\Capability\CapabilityProbeSource;
use Forwext\Core\Capability\CapabilityResolver;
use Forwext\Core\Capability\DatabaseServerCapabilityProbe;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use PHPUnit\Framework\TestCase;

final class CapabilityResolverTest extends TestCase
{
    public function testResolverBuildsPhpFunctionExtensionAndDatabaseMatrix(): void
    {
        $source = new FakeCapabilityProbeSource(
            '8.4.3',
            'fpm-fcgi',
            ['openssl', 'pdo', 'pdo_mysql', 'fileinfo'],
            ['random_bytes', 'json_encode', 'password_hash', 'finfo_open'],
            ['file_uploads' => '1', 'memory_limit' => '256M', 'upload_max_filesize' => '64M'],
            ['mysql'],
        );
        $database = new FakeCapabilityDatabase('10.11.6-MariaDB', 'MariaDB Server');
        $matrix = (new CapabilityResolver($source, new DatabaseServerCapabilityProbe($database)))->resolve();

        self::assertTrue($matrix->available('runtime.php_8_4'));
        self::assertTrue($matrix->available('extension.pdo_mysql'));
        self::assertFalse($matrix->available('extension.redis'));
        self::assertTrue($matrix->available('database.pdo_mysql_driver'));
        self::assertTrue($matrix->available('database.server_recognized'));
        self::assertTrue($matrix->available('database.innodb_fulltext'));
        self::assertSame([], $matrix->missingMinimum());
        self::assertSame('mariadb', $matrix->metadata['database_vendor']);
    }

    public function testMissingMinimumCapabilitiesAreReportedWithoutGuessing(): void
    {
        $source = new FakeCapabilityProbeSource(
            '8.3.0',
            'fpm-fcgi',
            ['pdo'],
            ['json_encode'],
            ['file_uploads' => '0'],
            [],
        );
        $matrix = (new CapabilityResolver($source))->resolve();
        $missingNames = array_map(
            static fn ($entry): string => $entry->name,
            $matrix->missingMinimum(),
        );

        self::assertContains('runtime.php_8_4', $missingNames);
        self::assertContains('extension.openssl', $missingNames);
        self::assertContains('extension.pdo_mysql', $missingNames);
        self::assertContains('function.random_bytes', $missingNames);
        self::assertContains('function.password_hash', $missingNames);
        self::assertContains('database.pdo_mysql_driver', $missingNames);
        self::assertContains('server.file_uploads', $missingNames);
    }
}

final readonly class FakeCapabilityProbeSource implements CapabilityProbeSource
{
    /**
     * @param list<string> $extensions
     * @param list<string> $functions
     * @param array<string, string> $ini
     * @param list<string> $pdoDrivers
     */
    public function __construct(
        private string $php,
        private string $sapiName,
        private array $extensions,
        private array $functions,
        private array $ini,
        private array $pdoDrivers,
    ) {
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function sapi(): string
    {
        return $this->sapiName;
    }

    public function extensionLoaded(string $extension): bool
    {
        return in_array($extension, $this->extensions, true);
    }

    public function extensionVersion(string $extension): ?string
    {
        return $this->extensionLoaded($extension) ? '1.0.0' : null;
    }

    public function functionAvailable(string $function): bool
    {
        return in_array($function, $this->functions, true);
    }

    public function iniValue(string $name): ?string
    {
        return $this->ini[$name] ?? null;
    }

    public function pdoDrivers(): array
    {
        return $this->pdoDrivers;
    }
}

final readonly class FakeCapabilityDatabase implements QueryExecutor
{
    public function __construct(
        private string $version,
        private string $comment,
    ) {
    }

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return [
            'version' => $this->version,
            'version_comment' => $this->comment,
        ];
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }
}
