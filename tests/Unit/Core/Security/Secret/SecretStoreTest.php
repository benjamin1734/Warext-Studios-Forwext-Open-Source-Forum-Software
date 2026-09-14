<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Security\Secret;

use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretException;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Security\Secret\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-secret-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testCipherRoundTripAndTamperDetection(): void
    {
        $cipher = new SecretCipher(SecretKey::generate());
        $encrypted = $cipher->encrypt('top-secret-value');

        self::assertSame('top-secret-value', $cipher->decrypt($encrypted));
        self::assertStringNotContainsString('top-secret-value', $encrypted);

        $tampered = substr_replace($encrypted, $encrypted[-2] === 'A' ? 'B' : 'A', -2, 1);
        $this->expectException(SecretException::class);
        $cipher->decrypt($tampered);
    }

    public function testEncryptedFileStoreNeverWritesPlaintextSecret(): void
    {
        $path = $this->directory . '/secrets.enc';
        $store = new EncryptedFileSecretStore($path, new SecretCipher(SecretKey::generate()));

        $store->set('oauth.google.client_secret', 'client-super-secret');
        $store->set('turnstile.secret', 'turnstile-secret-value');

        self::assertSame('client-super-secret', $store->get('oauth.google.client_secret'));
        self::assertTrue($store->has('turnstile.secret'));

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        self::assertStringNotContainsString('client-super-secret', $raw);
        self::assertStringNotContainsString('turnstile-secret-value', $raw);

        self::assertTrue($store->delete('turnstile.secret'));
        self::assertFalse($store->has('turnstile.secret'));
    }

    public function testFileKeyProviderCreatesAndReloadsProtectedKey(): void
    {
        $path = $this->directory . '/secret.key';
        $provider = new EnvironmentOrFileSecretKeyProvider($path, 'FORWEXT_TEST_MASTER_KEY_NOT_SET');
        $created = $provider->initializeFileIfMissing();
        $loaded = $provider->load();

        self::assertSame($created->exportBase64(), $loaded->exportBase64());
        self::assertFileExists($path);
    }

    public function testMaskerRedactsSensitiveKeysAndKnownSecretValues(): void
    {
        $masker = new SecretMasker(['known-token-value']);

        $masked = $masker->maskContext([
            'authorization' => 'Bearer anything',
            'message' => 'failed with known-token-value',
            'nested' => ['client_secret' => 'hidden'],
            'safe' => 'visible',
        ]);

        self::assertSame(SecretMasker::MASK, $masked['authorization']);
        self::assertSame('failed with ' . SecretMasker::MASK, $masked['message']);
        self::assertSame(SecretMasker::MASK, $masked['nested']['client_secret']);
        self::assertSame('visible', $masked['safe']);
    }
}
