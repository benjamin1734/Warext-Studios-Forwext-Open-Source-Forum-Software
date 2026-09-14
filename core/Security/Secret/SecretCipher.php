<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

use JsonException;
use SensitiveParameter;

final readonly class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const AAD = 'forwext.secret-store.v1';
    private const PREFIX = 'FWX1.';

    public function __construct(private SecretKey $key)
    {
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new SecretException('AES-256-GCM is not available in the active OpenSSL configuration.');
        }
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key->bytesForCrypto(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            self::TAG_BYTES,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new SecretException('Secret encryption failed.');
        }

        $payload = json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::PREFIX . base64_encode($payload);
    }

    public function decrypt(#[SensitiveParameter] string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new SecretException('Unsupported or invalid secret payload format.');
        }

        $json = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($json === false) {
            throw new SecretException('Invalid secret payload encoding.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SecretException('Invalid secret payload.', previous: $exception);
        }

        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            throw new SecretException('Unsupported secret payload version.');
        }

        $iv = $this->decodeField($decoded, 'iv');
        $tag = $this->decodeField($decoded, 'tag');
        $ciphertext = $this->decodeField($decoded, 'ciphertext');

        if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new SecretException('Invalid secret payload metadata.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key->bytesForCrypto(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
        );

        if ($plaintext === false) {
            throw new SecretException('Secret authentication/decryption failed.');
        }

        return $plaintext;
    }

    /** @param array<array-key, mixed> $payload */
    private function decodeField(array $payload, string $field): string
    {
        $encoded = $payload[$field] ?? null;
        if (!is_string($encoded)) {
            throw new SecretException('Invalid secret payload field.');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new SecretException('Invalid secret payload field encoding.');
        }

        return $decoded;
    }
}
