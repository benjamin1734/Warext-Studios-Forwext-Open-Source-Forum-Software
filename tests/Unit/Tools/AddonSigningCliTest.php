<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Tools;

use Forwext\Core\Addon\Security\AddonArtifactSignatureVerifier;
use Forwext\Core\Addon\Security\AddonSignaturePolicy;
use Forwext\Core\Addon\Security\AddonSignatureTrust;
use Forwext\Core\Addon\Security\AddonTrustedKey;
use Forwext\Core\Addon\Security\AddonTrustedKeyring;
use Forwext\Tools\Addon\AddonArtifactSigner;
use Forwext\Tools\Cli\CliApplication;
use Forwext\Tools\Cli\Command\AddonSignCommand;
use Forwext\Tools\Cli\Command\AddonVerifyCommand;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonSigningCliTest extends TestCase
{
    public function testSignAndVerifyCommandsSupportOfficialTrustPolicy(): void
    {
        $root = sys_get_temp_dir() . '/forwext-sign-cli-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        try {
            $artifact = $root . '/addon.zip';
            file_put_contents($artifact, 'artifact');
            [$privatePem, $publicPem] = $this->keyPair();

            $privatePath = $root . '/private.pem';
            $keyringPath = $root . '/trusted.json';
            file_put_contents($privatePath, $privatePem);
            file_put_contents($keyringPath, json_encode([
                'schema'=>1,
                'keys'=>[[
                    'id'=>'warext.release.test',
                    'public_key_pem'=>$publicPem,
                    'official'=>true,
                ]],
            ], JSON_THROW_ON_ERROR));

            $app = new CliApplication([
                new AddonSignCommand(new AddonArtifactSigner()),
                new AddonVerifyCommand(),
            ]);
            $signed = $app->run([
                'forwext','addon:sign',$artifact,'warext.release.test',$privatePath,
            ]);
            self::assertSame(0, $signed->exitCode);
            self::assertFileExists($artifact . '.sig.json');

            $verified = $app->run([
                'forwext','addon:verify',$artifact,$artifact . '.sig.json',$keyringPath,'official',
            ]);
            self::assertSame(0, $verified->exitCode);
            self::assertStringContainsString('Verified: official', $verified->stdout);
        } finally {
            $this->remove($root);
        }
    }

    public function testTamperedArtifactFailsClosed(): void
    {
        $root = sys_get_temp_dir() . '/forwext-sign-tamper-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        try {
            $artifact = $root . '/addon.zip';
            file_put_contents($artifact, 'original');
            [$privatePem, $publicPem] = $this->keyPair();
            $privatePath = $root . '/private.pem';
            file_put_contents($privatePath, $privatePem);

            $signature = (new AddonArtifactSigner())->sign(
                $artifact,
                'vendor.release.test',
                $privatePath,
            );
            file_put_contents($artifact, 'tampered');

            $this->expectException(InvalidArgumentException::class);
            (new AddonArtifactSignatureVerifier())->verify(
                $artifact,
                $signature,
                new AddonTrustedKeyring([
                    new AddonTrustedKey('vendor.release.test', $publicPem),
                ]),
                AddonSignaturePolicy::RequireSigned,
            );
        } finally {
            $this->remove($root);
        }
    }

    /** @return array{string,string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type'=>OPENSSL_KEYTYPE_RSA,
            'private_key_bits'=>2048,
        ]);
        self::assertNotFalse($resource);
        $privatePem = '';
        self::assertTrue(openssl_pkey_export($resource, $privatePem));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        $publicPem = $details['key'] ?? null;
        self::assertIsString($publicPem);

        return [$privatePem, $publicPem];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->remove($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
