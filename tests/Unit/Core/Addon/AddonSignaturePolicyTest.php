<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\Security\AddonArtifactSignatureVerifier;
use Forwext\Core\Addon\Security\AddonSignaturePolicy;
use Forwext\Core\Addon\Security\AddonSignatureTrust;
use Forwext\Core\Addon\Security\AddonTrustedKeyring;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonSignaturePolicyTest extends TestCase
{
    public function testOptionalPolicyAllowsOnlyTrulyUnspecifiedSignature(): void
    {
        $artifact = tempnam(sys_get_temp_dir(), 'forwext-artifact-');
        self::assertIsString($artifact);
        file_put_contents($artifact, 'artifact');

        try {
            $result = (new AddonArtifactSignatureVerifier())->verify(
                $artifact,
                null,
                new AddonTrustedKeyring(),
                AddonSignaturePolicy::Optional,
            );
            self::assertSame(AddonSignatureTrust::Unsigned, $result->trust);

            $this->expectException(InvalidArgumentException::class);
            (new AddonArtifactSignatureVerifier())->verify(
                $artifact,
                $artifact . '.missing.sig.json',
                new AddonTrustedKeyring(),
                AddonSignaturePolicy::Optional,
            );
        } finally {
            @unlink($artifact);
        }
    }
}
