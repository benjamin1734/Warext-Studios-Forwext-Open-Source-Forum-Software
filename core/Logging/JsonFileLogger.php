<?php

declare(strict_types=1);

namespace Forwext\Core\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Security\Secret\SecretMasker;
use RuntimeException;

final readonly class JsonFileLogger implements StructuredLogger
{
    public function __construct(
        private string $path,
        private SecretMasker $masker,
    ) {
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create structured-log directory.');
        }
        if (is_link($this->path)) {
            throw new RuntimeException('Structured log file may not be a symbolic link.');
        }

        $record = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level->value,
            'message' => $this->masker->maskString($message),
            'context' => $this->masker->maskContext($context),
        ];
        $payload = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (!is_string($payload)) {
            throw new RuntimeException('Unable to encode structured log record.');
        }

        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open structured log file.');
        }

        try {
            if (!@chmod($this->path, 0600)) {
                throw new RuntimeException('Unable to restrict permissions on structured log file.');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock structured log file.');
            }
            try {
                $line = $payload . PHP_EOL;
                if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                    throw new RuntimeException('Unable to append structured log record.');
                }
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
