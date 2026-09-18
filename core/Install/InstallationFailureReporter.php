<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use Throwable;

final readonly class InstallationFailureReporter
{
    public function __construct(private string $logPath)
    {
    }

    public function report(Throwable $failure): string
    {
        $reference = strtoupper(bin2hex(random_bytes(6)));
        $chain = $this->exceptionChain($failure);

        $directory = dirname($this->logPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $lines = [
            sprintf('[%s] install_ref=%s', gmdate('Y-m-d\TH:i:s\Z'), $reference),
        ];

        foreach ($chain as $index => $exception) {
            $lines[] = sprintf(
                '  #%d %s code=%s at %s:%d message=%s',
                $index,
                $exception::class,
                (string) $exception->getCode(),
                $exception->getFile(),
                $exception->getLine(),
                $this->sanitize($exception->getMessage()),
            );
        }

        if (is_dir($directory)) {
            @file_put_contents($this->logPath, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);
            if (is_file($this->logPath)) {
                @chmod($this->logPath, 0600);
            }
        }

        $top = $this->sanitize($failure->getMessage());
        $root = $this->sanitize($chain[array_key_last($chain)]->getMessage());

        if ($root !== '' && $root !== $top) {
            return sprintf('Kurulum başarısız [%s]: %s Alt neden: %s', $reference, $top, $root);
        }

        return sprintf('Kurulum başarısız [%s]: %s', $reference, $top);
    }

    /** @return list<Throwable> */
    private function exceptionChain(Throwable $failure): array
    {
        $chain = [];
        $current = $failure;

        do {
            $chain[] = $current;
            $current = $current->getPrevious();
        } while ($current instanceof Throwable && count($chain) < 12);

        return $chain;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';
        $message = preg_replace(
            '/((?:password|passwd|pwd)\s*[=:]\s*)[^\s;]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        if (strlen($message) > 700) {
            $message = substr($message, 0, 697) . '...';
        }

        return trim($message);
    }
}
