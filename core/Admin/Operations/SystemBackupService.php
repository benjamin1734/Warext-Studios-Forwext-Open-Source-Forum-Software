<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use JsonException;
use RuntimeException;

final readonly class SystemBackupService
{
    private const FORMAT = 'forwext.logical.backup.v1';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private string $directory,
        private int $chunkRows = 500,
    ) {
        if ($this->directory === '' || str_contains($this->directory, "\0")) {
            throw new RuntimeException('Backup directory is invalid.');
        }
        if ($this->chunkRows < 50 || $this->chunkRows > 2000) {
            throw new RuntimeException('Backup chunk size must be between 50 and 2000 rows.');
        }
    }

    public function create(DateTimeImmutable $at): SystemBackupEntry
    {
        $this->ensureDirectory();
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        $name = 'forwext-backup-' . $at->format('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl';
        $temporary = $this->directory . '/.' . $name . '.tmp';
        $target = $this->path($name);

        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create backup staging file.');
        }

        $tableCount = 0;
        $rowCount = 0;
        try {
            @chmod($temporary, 0600);
            $this->writeLine($handle, [
                'type'=>'header',
                'format'=>self::FORMAT,
                'created_at_utc'=>$at->format('Y-m-d\TH:i:s.u\Z'),
            ]);

            $this->database->execute(new CompiledQuery('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'));
            $this->database->execute(new CompiledQuery('SET TRANSACTION READ ONLY'));
            $this->database->transaction(function () use ($handle, &$tableCount, &$rowCount): void {
                $tables = $this->database->fetchAll(new CompiledQuery(
                    "SELECT TABLE_NAME FROM information_schema.TABLES "
                    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' "
                    . "AND TABLE_NAME LIKE 'forwext\\_%' ESCAPE '\\\\' ORDER BY TABLE_NAME ASC"
                ));

                foreach ($tables as $tableRow) {
                    $table = $tableRow['TABLE_NAME'] ?? null;
                    if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                        throw new RuntimeException('Backup encountered an invalid table name.');
                    }

                    $createRow = $this->database->fetchOne(new CompiledQuery('SHOW CREATE TABLE ' . $table));
                    if ($createRow === null) {
                        throw new RuntimeException('Backup could not read table schema.');
                    }
                    $createSql = null;
                    foreach ($createRow as $value) {
                        if (is_string($value) && str_starts_with($value, 'CREATE TABLE')) {
                            $createSql = $value;
                            break;
                        }
                    }
                    if ($createSql === null) {
                        throw new RuntimeException('Backup table schema payload is invalid.');
                    }

                    $this->writeLine($handle, [
                        'type'=>'table',
                        'name'=>$table,
                        'create_sql'=>$createSql,
                    ]);
                    ++$tableCount;

                    for ($offset = 0; ; $offset += $this->chunkRows) {
                        $rows = $this->database->fetchAll(new CompiledQuery(
                            'SELECT * FROM ' . $table . ' LIMIT ' . $this->chunkRows . ' OFFSET ' . $offset
                        ));
                        foreach ($rows as $row) {
                            $this->writeLine($handle, [
                                'type'=>'row',
                                'table'=>$table,
                                'data'=>self::encodeRow($row),
                            ]);
                            ++$rowCount;
                        }
                        if (count($rows) < $this->chunkRows) {
                            break;
                        }
                    }
                }
            });

            $this->writeLine($handle, [
                'type'=>'manifest',
                'tables'=>$tableCount,
                'rows'=>$rowCount,
            ]);
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush backup staging file.');
            }
        } catch (\Throwable $failure) {
            fclose($handle);
            @unlink($temporary);
            throw $failure;
        }
        fclose($handle);

        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically publish backup file.');
        }
        @chmod($target, 0600);

        return $this->entry($name, hash_file('sha256', $target) ?: null, true, $tableCount, $rowCount);
    }

    /** @return list<SystemBackupEntry> */
    public function list(): array
    {
        $this->ensureDirectory();
        $items = [];
        $files = scandir($this->directory);
        if (!is_array($files)) {
            throw new RuntimeException('Backup directory cannot be listed.');
        }
        foreach ($files as $name) {
            if (!is_string($name) || preg_match('/^forwext-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.jsonl$/D', $name) !== 1) {
                continue;
            }
            $path = $this->path($name);
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $items[] = $this->entry($name);
        }
        usort($items, static fn (SystemBackupEntry $a, SystemBackupEntry $b): int => $b->modifiedAt <=> $a->modifiedAt);

        return $items;
    }

    public function verify(string $name): SystemBackupEntry
    {
        $path = $this->path($name);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Backup file was not found or is unsafe.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Backup file cannot be opened.');
        }

        $tableCount = 0;
        $rowCount = 0;
        $headerSeen = false;
        $manifest = null;
        try {
            while (($line = fgets($handle)) !== false) {
                if (strlen($line) > 16777216) {
                    throw new RuntimeException('Backup contains an oversized record.');
                }
                try {
                    $record = json_decode(trim($line), true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Backup contains invalid JSON.', previous: $exception);
                }
                if (!is_array($record) || !is_string($record['type'] ?? null)) {
                    throw new RuntimeException('Backup record shape is invalid.');
                }
                if (!$headerSeen) {
                    if (($record['type'] ?? null) !== 'header' || ($record['format'] ?? null) !== self::FORMAT) {
                        throw new RuntimeException('Backup header is invalid.');
                    }
                    $headerSeen = true;
                    continue;
                }

                if ($record['type'] === 'table') {
                    ++$tableCount;
                } elseif ($record['type'] === 'row') {
                    ++$rowCount;
                } elseif ($record['type'] === 'manifest') {
                    $manifest = $record;
                }
            }
        } finally {
            fclose($handle);
        }

        $verified = $headerSeen
            && is_array($manifest)
            && (int) ($manifest['tables'] ?? -1) === $tableCount
            && (int) ($manifest['rows'] ?? -1) === $rowCount;

        return $this->entry(
            $name,
            hash_file('sha256', $path) ?: null,
            $verified,
            $tableCount,
            $rowCount,
        );
    }

    public function restore(string $name, string $expectedSha256): SystemBackupEntry
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new RuntimeException('Backup restore checksum is invalid.');
        }

        $entry = $this->verify($name);
        if (!$entry->verified || $entry->sha256 === null || !hash_equals($expectedSha256, $entry->sha256)) {
            throw new RuntimeException('Backup restore checksum or manifest verification failed.');
        }

        $path = $this->path($name);
        $this->validateRestorePayload($path);

        $tables = $this->database->fetchAll(new CompiledQuery(
            "SELECT TABLE_NAME FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' "
            . "AND TABLE_NAME LIKE 'forwext\\_%' ESCAPE '\\\\' ORDER BY TABLE_NAME DESC"
        ));

        $this->database->execute(new CompiledQuery('SET FOREIGN_KEY_CHECKS=0'));
        try {
            foreach ($tables as $row) {
                $table = $row['TABLE_NAME'] ?? null;
                if (!is_string($table) || !self::validIdentifier($table)) {
                    throw new RuntimeException('Backup restore encountered an invalid existing table name.');
                }
                $this->database->execute(new CompiledQuery('DROP TABLE IF EXISTS ' . self::quoteIdentifier($table)));
            }

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Backup restore file cannot be opened.');
            }

            $currentTable = null;
            try {
                while (($line = fgets($handle)) !== false) {
                    $record = self::decodeRecord($line);
                    $type = $record['type'];

                    if ($type === 'table') {
                        $table = $record['name'] ?? null;
                        $createSql = $record['create_sql'] ?? null;
                        if (!is_string($table) || !is_string($createSql)) {
                            throw new RuntimeException('Backup restore table record is invalid.');
                        }
                        $this->database->execute(new CompiledQuery($createSql));
                        $currentTable = $table;
                        continue;
                    }

                    if ($type !== 'row') {
                        continue;
                    }

                    $table = $record['table'] ?? null;
                    $data = $record['data'] ?? null;
                    if (!is_string($table) || $table !== $currentTable || !is_array($data)) {
                        throw new RuntimeException('Backup restore row record is invalid.');
                    }

                    [$columns, $parameters] = self::decodeRowForRestore($data);
                    if ($columns === []) {
                        throw new RuntimeException('Backup restore row has no columns.');
                    }

                    $columnSql = implode(',', array_map(self::quoteIdentifier(...), $columns));
                    $placeholderSql = implode(',', array_map(
                        static fn (int $index): string => ':v' . $index,
                        array_keys($columns),
                    ));
                    $this->database->execute(new CompiledQuery(
                        'INSERT INTO ' . self::quoteIdentifier($table)
                        . ' (' . $columnSql . ') VALUES (' . $placeholderSql . ')',
                        $parameters,
                    ));
                }
            } finally {
                fclose($handle);
            }
        } finally {
            $this->database->execute(new CompiledQuery('SET FOREIGN_KEY_CHECKS=1'));
        }

        return $this->verify($name);
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Backup file was not found or is unsafe.');
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Backup file could not be deleted.');
        }
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Backup directory may not be a symbolic link.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Backup directory cannot be created.');
        }
        @chmod($this->directory, 0700);
    }

    private function path(string $name): string
    {
        if (preg_match('/^forwext-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.jsonl$/D', $name) !== 1) {
            throw new RuntimeException('Backup filename is invalid.');
        }

        return rtrim($this->directory, '/\\') . '/' . $name;
    }

    private function entry(
        string $name,
        ?string $sha256 = null,
        ?bool $verified = null,
        ?int $tables = null,
        ?int $rows = null,
    ): SystemBackupEntry {
        $path = $this->path($name);
        $size = filesize($path);
        $mtime = filemtime($path);
        if (!is_int($size) || !is_int($mtime)) {
            throw new RuntimeException('Backup metadata is unavailable.');
        }

        return new SystemBackupEntry(
            $name,
            $size,
            (new DateTimeImmutable('@' . $mtime))->setTimezone(new DateTimeZone('UTC')),
            $sha256,
            $verified,
            $tables,
            $rows,
        );
    }

    private function validateRestorePayload(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Backup restore file cannot be opened.');
        }

        $headerSeen = false;
        $manifestSeen = false;
        $tables = [];
        $currentTable = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $record = self::decodeRecord($line);
                $type = $record['type'];

                if (!$headerSeen) {
                    if ($type !== 'header' || ($record['format'] ?? null) !== self::FORMAT) {
                        throw new RuntimeException('Backup restore header is invalid.');
                    }
                    $headerSeen = true;
                    continue;
                }

                if ($manifestSeen) {
                    throw new RuntimeException('Backup restore contains records after its manifest.');
                }

                if ($type === 'table') {
                    $name = $record['name'] ?? null;
                    $createSql = $record['create_sql'] ?? null;
                    if (
                        !is_string($name)
                        || !self::validIdentifier($name)
                        || isset($tables[$name])
                        || !is_string($createSql)
                        || preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `?' . preg_quote($name, '/') . '`?\\s/iD', $createSql) !== 1
                    ) {
                        throw new RuntimeException('Backup restore table definition is invalid.');
                    }
                    $tables[$name] = true;
                    $currentTable = $name;
                    continue;
                }

                if ($type === 'row') {
                    $table = $record['table'] ?? null;
                    $data = $record['data'] ?? null;
                    if (!is_string($table) || $table !== $currentTable || !is_array($data) || $data === []) {
                        throw new RuntimeException('Backup restore row ordering is invalid.');
                    }
                    self::decodeRowForRestore($data);
                    continue;
                }

                if ($type === 'manifest') {
                    $manifestSeen = true;
                    continue;
                }

                throw new RuntimeException('Backup restore record type is unsupported.');
            }
        } finally {
            fclose($handle);
        }

        if (!$headerSeen || !$manifestSeen) {
            throw new RuntimeException('Backup restore payload is incomplete.');
        }
    }

    /** @return array<string,mixed> */
    private static function decodeRecord(string $line): array
    {
        if (strlen($line) > 16777216) {
            throw new RuntimeException('Backup contains an oversized record.');
        }
        try {
            $record = json_decode(trim($line), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup contains invalid JSON.', previous: $exception);
        }
        if (!is_array($record) || !is_string($record['type'] ?? null)) {
            throw new RuntimeException('Backup record shape is invalid.');
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:list<string>,1:array<string,string|int|float|bool|null>}
     */
    private static function decodeRowForRestore(array $data): array
    {
        $columns = [];
        $parameters = [];
        foreach ($data as $column => $encoded) {
            if (!is_string($column) || !self::validIdentifier($column) || !is_array($encoded)) {
                throw new RuntimeException('Backup restore row column is invalid.');
            }
            $type = $encoded['type'] ?? null;
            if (!is_string($type)) {
                throw new RuntimeException('Backup restore row value type is invalid.');
            }

            $value = match ($type) {
                'null' => null,
                'int' => self::restoreInteger($encoded['value'] ?? null),
                'float' => self::restoreFloat($encoded['value'] ?? null),
                'bool' => self::restoreBool($encoded['value'] ?? null),
                'base64' => self::restoreBinary($encoded['value'] ?? null),
                default => throw new RuntimeException('Backup restore row value type is unsupported.'),
            };

            $index = count($columns);
            $columns[] = $column;
            $parameters['v' . $index] = $value;
        }

        return [$columns, $parameters];
    }

    private static function restoreInteger(mixed $value): int
    {
        if (!is_int($value)) {
            throw new RuntimeException('Backup restore integer value is invalid.');
        }
        return $value;
    }

    private static function restoreFloat(mixed $value): float
    {
        if (!is_float($value) && !is_int($value)) {
            throw new RuntimeException('Backup restore float value is invalid.');
        }
        return (float) $value;
    }

    private static function restoreBool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException('Backup restore boolean value is invalid.');
        }
        return $value;
    }

    private static function restoreBinary(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Backup restore base64 value is invalid.');
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException('Backup restore base64 value is invalid.');
        }
        return $decoded;
    }

    private static function validIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value) === 1;
    }

    private static function quoteIdentifier(string $value): string
    {
        if (!self::validIdentifier($value)) {
            throw new RuntimeException('Backup SQL identifier is invalid.');
        }
        return '`' . $value . '`';
    }

    /** @param resource $handle @param array<string,mixed> $record */
    private function writeLine($handle, array $record): void
    {
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup record cannot be encoded.', previous: $exception);
        }
        $line = $json . PHP_EOL;
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException('Backup record cannot be written.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,array{type:string,value?:int|float|bool|string}> */
    private static function encodeRow(array $row): array
    {
        $encoded = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if ($value === null) {
                $encoded[$column] = ['type'=>'null'];
            } elseif (is_int($value)) {
                $encoded[$column] = ['type'=>'int','value'=>$value];
            } elseif (is_float($value)) {
                $encoded[$column] = ['type'=>'float','value'=>$value];
            } elseif (is_bool($value)) {
                $encoded[$column] = ['type'=>'bool','value'=>$value];
            } else {
                $encoded[$column] = ['type'=>'base64','value'=>base64_encode((string) $value)];
            }
        }

        return $encoded;
    }
}
