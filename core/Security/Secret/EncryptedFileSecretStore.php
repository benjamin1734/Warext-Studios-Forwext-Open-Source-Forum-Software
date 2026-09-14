<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

use Closure;
use JsonException;
use SensitiveParameter;

final readonly class EncryptedFileSecretStore implements SecretStore
{
    public function __construct(
        private string $path,
        private SecretCipher $cipher,
    ) {
    }

    public function has(string $name): bool
    {
        $this->validateName($name);
        return array_key_exists($name, $this->all());
    }

    public function get(string $name): ?string
    {
        $this->validateName($name);
        return $this->all()[$name] ?? null;
    }

    public function set(string $name, #[SensitiveParameter] string $value): void
    {
        $this->validateName($name);

        $this->withLock(LOCK_EX, function () use ($name, $value): void {
            $secrets = $this->readUnlocked();
            $secrets[$name] = $value;
            $this->writeUnlocked($secrets);
        });
    }

    public function delete(string $name): bool
    {
        $this->validateName($name);

        return $this->withLock(LOCK_EX, function () use ($name): bool {
            $secrets = $this->readUnlocked();
            if (!array_key_exists($name, $secrets)) {
                return false;
            }

            unset($secrets[$name]);
            $this->writeUnlocked($secrets);
            return true;
        });
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->withLock(LOCK_SH, fn (): array => $this->readUnlocked());
    }

    private function validateName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,191}$/', $name) !== 1) {
            throw new SecretException('Secret name contains unsupported characters or length.');
        }
    }

    /** @return array<string, string> */
    private function readUnlocked(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        if (is_link($this->path)) {
            throw new SecretException('Encrypted secret store may not be a symbolic link.');
        }

        $payload = @file_get_contents($this->path);
        if ($payload === false) {
            throw new SecretException('Unable to read the encrypted secret store.');
        }

        $plaintext = $this->cipher->decrypt($payload);

        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SecretException('Decrypted secret store is invalid.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new SecretException('Decrypted secret store must contain an object.');
        }

        $secrets = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new SecretException('Decrypted secret store contains an invalid entry.');
            }
            $this->validateName($name);
            $secrets[$name] = $value;
        }

        return $secrets;
    }

    /** @param array<string, string> $secrets */
    private function writeUnlocked(array $secrets): void
    {
        if (is_link($this->path)) {
            throw new SecretException('Encrypted secret store may not be a symbolic link.');
        }

        $plaintext = json_encode($secrets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payload = $this->cipher->encrypt($plaintext);
        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            $written = @file_put_contents($temporary, $payload, LOCK_EX);
            if ($written !== strlen($payload)) {
                throw new SecretException('Unable to stage encrypted secret-store data.');
            }

            if (!@chmod($temporary, 0600)) {
                throw new SecretException('Unable to restrict permissions on staged secret-store data.');
            }

            if (!@rename($temporary, $this->path)) {
                throw new SecretException('Unable to atomically replace the encrypted secret store.');
            }

            if (!@chmod($this->path, 0600)) {
                throw new SecretException('Unable to restrict permissions on the encrypted secret store.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SecretException('Unable to create the encrypted secret-store directory.');
        }
    }

    private function withLock(int $operation, Closure $callback): mixed
    {
        $this->ensureDirectory();
        $lockPath = $this->path . '.lock';

        if (is_link($lockPath)) {
            throw new SecretException('Secret-store lock file may not be a symbolic link.');
        }

        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new SecretException('Unable to open the secret-store lock file.');
        }

        try {
            @chmod($lockPath, 0600);
            if (!flock($handle, $operation)) {
                throw new SecretException('Unable to acquire the secret-store file lock.');
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
}
