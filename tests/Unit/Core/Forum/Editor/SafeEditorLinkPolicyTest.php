<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use PHPUnit\Framework\TestCase;

final class SafeEditorLinkPolicyTest extends TestCase
{
    public function testOnlySafeHttpsOrUnambiguousSiteRelativeTargetsAreAccepted(): void
    {
        $policy = new SafeEditorLinkPolicy();

        self::assertSame('https://example.com/path?q=1', $policy->normalize('https://example.com/path?q=1'));
        self::assertSame('/threads/123', $policy->normalize('/threads/123'));

        foreach ([
            'http://example.com',
            'javascript:alert(1)',
            '//example.com/path',
            'https://user:pass@example.com/private',
            '/threads\\..\\admin',
            "https://example.com/\nheader",
        ] as $unsafe) {
            self::assertNull($policy->normalize($unsafe), $unsafe);
        }
    }
}
