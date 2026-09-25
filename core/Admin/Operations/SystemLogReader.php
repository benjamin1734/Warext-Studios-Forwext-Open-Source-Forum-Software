<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use JsonException;
use RuntimeException;

final readonly class SystemLogReader
{
    private const MAX_READ_BYTES = 2097152;

    public function __construct(private string $path)
    {
        if ($this->path === '' || str_contains($this->path, "\0")) {
            throw new RuntimeException('System log path is invalid.');
        }
    }

    /** @return list<SystemLogEntry> */
    public function tail(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 250) {
            throw new RuntimeException('System log tail limit must be between 1 and 250.');
        }
        if (!is_file($this->path)) {
            return [];
        }
        if (is_link($this->path)) {
            throw new RuntimeException('System log file may not be a symbolic link.');
        }

        $size = filesize($this->path);
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException('System log file size is unavailable.');
        }

        $readBytes = min($size, self::MAX_READ_BYTES);
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('System log file cannot be opened.');
        }

        try {
            if ($readBytes > 0 && fseek($handle, -$readBytes, SEEK_END) !== 0) {
                throw new RuntimeException('System log file cannot be seeked.');
            }
            $payload = $readBytes === 0 ? '' : fread($handle, $readBytes);
            if (!is_string($payload)) {
                throw new RuntimeException('System log file cannot be read.');
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\R/', $payload) ?: [];
        if ($size > $readBytes && $lines !== []) {
            array_shift($lines);
        }
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        $lines = array_slice($lines, -$limit);

        $entries = [];
        foreach ($lines as $line) {
            try {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $timestamp = $row['timestamp'] ?? null;
            $level = $row['level'] ?? null;
            $message = $row['message'] ?? null;
            $context = $row['context'] ?? [];
            if (!is_string($timestamp) || !is_string($level) || !is_string($message) || !is_array($context)) {
                continue;
            }
            $entries[] = new SystemLogEntry(
                $timestamp,
                strtolower($level),
                $message,
                self::redactContext($context),
            );
        }

        return array_reverse($entries);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function redactContext(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if (preg_match('/(?:password|passwd|secret|token|authorization|cookie|api[_-]?key|private[_-]?key)/i', $key) === 1) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = self::redactContext($value);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
