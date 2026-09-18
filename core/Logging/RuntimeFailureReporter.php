<?php

declare(strict_types=1);

namespace Forwext\Core\Logging;

use Throwable;

final readonly class RuntimeFailureReporter
{
    public function __construct(private string $logPath)
    {
    }

    public function report(Throwable $failure, ?string $requestUri = null): string
    {
        $reference = strtoupper(bin2hex(random_bytes(6)));
        $directory = dirname($this->logPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $requestPath = $this->requestPath($requestUri);
        $lines = [
            sprintf(
                '[%s] runtime_ref=%s%s',
                gmdate('Y-m-d\TH:i:s\Z'),
                $reference,
                $requestPath === null ? '' : ' path=' . $requestPath,
            ),
        ];

        $current = $failure;
        $index = 0;
        do {
            $lines[] = sprintf(
                '  #%d %s code=%s at %s:%d message=%s',
                $index,
                $current::class,
                (string) $current->getCode(),
                $current->getFile(),
                $current->getLine(),
                $this->sanitize($current->getMessage()),
            );
            $current = $current->getPrevious();
            ++$index;
        } while ($current instanceof Throwable && $index < 12);

        if (is_dir($directory)) {
            @file_put_contents(
                $this->logPath,
                implode(PHP_EOL, $lines) . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
            if (is_file($this->logPath)) {
                @chmod($this->logPath, 0600);
            }
        }

        return $reference;
    }

    private function requestPath(?string $requestUri): ?string
    {
        if ($requestUri === null || $requestUri === '') {
            return null;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';
        if ($path === '') {
            return null;
        }

        return strlen($path) > 300 ? substr($path, 0, 300) : $path;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';
        $message = preg_replace(
            '/((?:password|passwd|pwd|secret|token|authorization)\s*[=:]\s*)[^\s;]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        if (strlen($message) > 700) {
            $message = substr($message, 0, 697) . '...';
        }

        return trim($message);
    }
}
