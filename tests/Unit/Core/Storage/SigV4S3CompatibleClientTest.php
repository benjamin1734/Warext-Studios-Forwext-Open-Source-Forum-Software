<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Storage;

use Forwext\Core\Storage\S3\SigV4S3CompatibleClient;
use Forwext\Core\Storage\StorageException;
use PHPUnit\Framework\TestCase;

final class SigV4S3CompatibleClientTest extends TestCase
{
    public function testRejectsCredentialBearingEndpoint(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('cURL is unavailable.');
        }

        $this->expectException(StorageException::class);

        new SigV4S3CompatibleClient(
            'https://user:pass@objects.example.test',
            'us-east-1',
            'access-key',
            'secret-key',
        );
    }

    public function testPresignedUrlContainsBoundedSigV4ContractWithoutSecret(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('cURL is unavailable.');
        }

        $client = new SigV4S3CompatibleClient(
            'https://objects.example.test:9443/prefix',
            'eu-central-1',
            'AKIDEXAMPLE',
            'super-secret-value',
        );

        $url = $client->temporaryPrivateUrl('forwext-media', 'private/user 1/avatar.png', 300);

        self::assertStringStartsWith(
            'https://objects.example.test:9443/prefix/forwext-media/private/user%201/avatar.png?',
            $url,
        );
        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContainsString('X-Amz-Expires=300', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
        self::assertStringNotContainsString('super-secret-value', $url);
    }
}
