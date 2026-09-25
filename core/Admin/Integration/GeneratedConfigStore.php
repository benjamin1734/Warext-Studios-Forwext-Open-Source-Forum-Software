<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use Closure;
use Forwext\Core\Config\ConfigException;

final readonly class GeneratedConfigStore
{
    public function __construct(private string $path)
    {
        if ($this->path === '' || str_contains($this->path, "\0")) {
            throw new ConfigException('Generated configuration path is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->withLock(LOCK_SH, fn (): array => $this->readUnlocked());
    }

    public function set(string $path, bool|int|string|array|null $value): void
    {
        $segments = self::segments($path);
        $this->withLock(LOCK_EX, function () use ($segments, $value): void {
            $data = $this->readUnlocked();
            $cursor =& $data;
            foreach ($segments as $index => $segment) {
                if ($index === array_key_last($segments)) {
                    $cursor[$segment] = $value;
                    break;
                }
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment]) || array_is_list($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor =& $cursor[$segment];
            }
            $this->writeUnlocked($data);
        });
    }

    public function delete(string $path): void
    {
        $segments = self::segments($path);
        $this->withLock(LOCK_EX, function () use ($segments): void {
            $data = $this->readUnlocked();
            self::deleteRecursive($data, $segments, 0);
            $this->writeUnlocked($data);
        });
    }

    /** @return array<string,mixed> */
    private function readUnlocked(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        if (is_link($this->path)) {
            throw new ConfigException('Generated configuration file may not be a symbolic link.');
        }
        $loader = static fn (string $file): mixed => require $file;
        $data = $loader($this->path);
        if (!is_array($data)) {
            throw new ConfigException('Generated configuration file must return an array.');
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    private function writeUnlocked(array $data): void
    {
        if (is_link($this->path)) {
            throw new ConfigException('Generated configuration file may not be a symbolic link.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($data, true) . ";\n";
        $temporary = @tempnam(dirname($this->path), '.forwext-config-');
        if (!is_string($temporary) || $temporary === '') {
            throw new ConfigException('Unable to stage generated configuration.');
        }

        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
                throw new ConfigException('Unable to write generated configuration.');
            }
            @chmod($temporary, 0640);
            if (!@rename($temporary, $this->path)) {
                throw new ConfigException('Unable to atomically replace generated configuration.');
            }
            @chmod($this->path, 0640);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ConfigException('Unable to create generated configuration directory.');
        }
        if (is_link($directory) || (is_file($this->path) && is_link($this->path))) {
            throw new ConfigException('Generated configuration path may not use symbolic links.');
        }
    }

    private function withLock(int $operation, Closure $callback): mixed
    {
        $this->ensureDirectory();
        $lockPath = $this->path . '.lock';
        if (is_link($lockPath)) {
            throw new ConfigException('Generated configuration lock may not be a symbolic link.');
        }

        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new ConfigException('Unable to open generated configuration lock.');
        }

        try {
            @chmod($lockPath, 0640);
            if (!flock($handle, $operation)) {
                throw new ConfigException('Unable to lock generated configuration.');
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return non-empty-list<string> */
    private static function segments(string $path): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $path) !== 1) {
            throw new ConfigException('Generated configuration key is invalid.');
        }
        /** @var non-empty-list<string> $segments */
        $segments = explode('.', $path);

        return $segments;
    }

    /**
     * @param array<string,mixed> $data
     * @param non-empty-list<string> $segments
     */
    private static function deleteRecursive(array &$data, array $segments, int $index): bool
    {
        $segment = $segments[$index];
        if (!array_key_exists($segment, $data)) {
            return $data === [];
        }
        if ($index === array_key_last($segments)) {
            unset($data[$segment]);

            return $data === [];
        }
        if (!is_array($data[$segment]) || array_is_list($data[$segment])) {
            return $data === [];
        }
        /** @var array<string,mixed> $child */
        $child = $data[$segment];
        $empty = self::deleteRecursive($child, $segments, $index + 1);
        if ($empty) {
            unset($data[$segment]);
        } else {
            $data[$segment] = $child;
        }

        return $data === [];
    }
}
