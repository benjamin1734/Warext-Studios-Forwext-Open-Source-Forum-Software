<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

final readonly class EnvironmentOrFileSecretKeyProvider implements SecretKeyProvider
{
    public function __construct(
        private string $filePath,
        private string $environmentVariable = 'FORWEXT_MASTER_KEY',
    ) {
    }

    public function load(): SecretKey
    {
        $environmentValue = getenv($this->environmentVariable);
        if (is_string($environmentValue) && trim($environmentValue) !== '') {
            return SecretKey::fromBase64($environmentValue);
        }

        return $this->loadFileKey();
    }

    public function initializeFileIfMissing(): SecretKey
    {
        $environmentValue = getenv($this->environmentVariable);
        if (is_string($environmentValue) && trim($environmentValue) !== '') {
            return SecretKey::fromBase64($environmentValue);
        }

        if (is_file($this->filePath)) {
            return $this->loadFileKey();
        }

        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SecretException('Unable to create the Forwext secret-key directory.');
        }

        $key = SecretKey::generate();
        $handle = @fopen($this->filePath, 'x+b');

        if ($handle === false) {
            if (is_file($this->filePath)) {
                return $this->loadFileKey();
            }

            throw new SecretException('Unable to create the Forwext master-key file.');
        }

        $completed = false;

        try {
            $payload = $key->exportBase64() . PHP_EOL;
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new SecretException('Unable to persist the Forwext master-key file.');
            }

            if (!@chmod($this->filePath, 0600)) {
                throw new SecretException('Unable to restrict permissions on the Forwext master-key file.');
            }

            $completed = true;
        } finally {
            fclose($handle);

            if (!$completed && is_file($this->filePath) && !is_link($this->filePath)) {
                @unlink($this->filePath);
            }
        }

        return $key;
    }

    private function loadFileKey(): SecretKey
    {
        if (is_link($this->filePath)) {
            throw new SecretException('Forwext master-key file may not be a symbolic link.');
        }

        $encoded = @file_get_contents($this->filePath);
        if ($encoded === false) {
            throw new SecretException(sprintf(
                'Forwext master key is unavailable. Set %s or provide the protected key file.',
                $this->environmentVariable,
            ));
        }

        return SecretKey::fromBase64($encoded);
    }
}
