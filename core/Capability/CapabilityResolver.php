<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

final readonly class CapabilityResolver
{
    public function __construct(
        private CapabilityProbeSource $source = new NativeCapabilityProbeSource(),
        private ?DatabaseServerCapabilityProbe $databaseProbe = null,
    ) {
    }

    public function resolve(): CapabilityMatrix
    {
        $entries = [
            new CapabilityEntry('runtime.php_8_4', version_compare($this->source->phpVersion(), '8.4.0', '>='), true, $this->source->phpVersion()),
            $this->extension('openssl', true),
            $this->extension('pdo', true),
            $this->extension('pdo_mysql', true),
            $this->extension('redis'),
            $this->extension('intl'),
            $this->extension('fileinfo'),
            $this->extension('gd'),
            $this->extension('imagick'),
            $this->extension('sodium'),
            $this->function('random_bytes', true),
            $this->function('json_encode', true),
            $this->function('password_hash', true),
            $this->function('finfo_open'),
            $this->function('proc_open'),
            $this->function('exec'),
            new CapabilityEntry(
                'database.pdo_mysql_driver',
                in_array('mysql', $this->source->pdoDrivers(), true),
                true,
            ),
            new CapabilityEntry(
                'server.file_uploads',
                self::iniBoolean($this->source->iniValue('file_uploads')),
                true,
            ),
            new CapabilityEntry('server.cli', strtolower($this->source->sapi()) === 'cli'),
        ];

        $metadata = [
            'php_version' => $this->source->phpVersion(),
            'sapi' => $this->source->sapi(),
            'memory_limit' => $this->source->iniValue('memory_limit'),
            'upload_max_filesize' => $this->source->iniValue('upload_max_filesize'),
            'post_max_size' => $this->source->iniValue('post_max_size'),
            'max_execution_time' => $this->source->iniValue('max_execution_time'),
            'pdo_drivers' => $this->source->pdoDrivers(),
        ];

        if ($this->databaseProbe !== null) {
            $database = $this->databaseProbe->probe();
            $entries[] = new CapabilityEntry(
                'database.server_recognized',
                $database->recognized,
                false,
                $database->vendor . ':' . $database->version,
            );
            $entries[] = new CapabilityEntry(
                'database.innodb_fulltext',
                $database->innodbFulltext,
                false,
                $database->vendor . ':' . $database->version,
            );
            $metadata['database_vendor'] = $database->vendor;
            $metadata['database_version'] = $database->version;
        }

        return new CapabilityMatrix($entries, $metadata);
    }

    private function extension(string $extension, bool $required = false): CapabilityEntry
    {
        return new CapabilityEntry(
            'extension.' . $extension,
            $this->source->extensionLoaded($extension),
            $required,
            $this->source->extensionVersion($extension),
        );
    }

    private function function(string $function, bool $required = false): CapabilityEntry
    {
        return new CapabilityEntry(
            'function.' . $function,
            $this->source->functionAvailable($function),
            $required,
        );
    }

    private static function iniBoolean(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return !in_array(strtolower(trim($value)), ['', '0', 'off', 'false', 'no', 'none'], true);
    }
}
