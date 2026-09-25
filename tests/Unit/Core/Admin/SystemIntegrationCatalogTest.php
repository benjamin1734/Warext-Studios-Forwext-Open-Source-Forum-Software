<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Integration\IntegrationSection;
use Forwext\Core\Admin\Integration\SystemIntegrationCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SystemIntegrationCatalogTest extends TestCase
{
    public function testCoreCatalogCoversEveryRoadmapIntegrationSection(): void
    {
        $catalog = SystemIntegrationCatalog::coreDefaults();
        $covered = [];

        foreach ($catalog->settings() as $setting) {
            $covered[$setting->section->value] = true;
        }
        foreach ($catalog->secrets() as $secret) {
            $covered[$secret->section->value] = true;
        }

        foreach (IntegrationSection::cases() as $section) {
            self::assertArrayHasKey($section->value, $covered);
        }

        self::assertNotEmpty($catalog->settings());
        self::assertNotEmpty($catalog->secrets());
    }

    public function testApiAndWebhookSwitchesStayReadOnlyUntilRoadmapStepNineteen(): void
    {
        $catalog = SystemIntegrationCatalog::coreDefaults();

        self::assertFalse($catalog->setting('integration.api.enabled')->editable);
        self::assertFalse($catalog->setting('integration.webhooks.enabled')->editable);

        $this->expectException(InvalidArgumentException::class);
        $catalog->setting('integration.api.enabled')->normalize('1');
    }

    public function testEndpointAndSameOriginValidationRejectUnsafeShapes(): void
    {
        $catalog = SystemIntegrationCatalog::coreDefaults();

        $rejected = 0;
        foreach ([
            'http://example.com/moderate',
            'https://user:pass@example.com/moderate',
            'https://example.com/moderate#secret',
        ] as $unsafe) {
            try {
                $catalog->setting('integration.ai.custom_endpoint')->normalize($unsafe);
                self::fail('Unsafe HTTPS endpoint shape was accepted: ' . $unsafe);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        foreach (['//gateway/ws', '/a/../ws', '/ws?token=x'] as $unsafePath) {
            try {
                $catalog->setting('integration.realtime.websocket_path')->normalize($unsafePath);
                self::fail('Unsafe same-origin path was accepted: ' . $unsafePath);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(6, $rejected);
    }

    public function testSecretDefinitionsNeverExposeDefaultSecretValues(): void
    {
        $catalog = SystemIntegrationCatalog::coreDefaults();

        foreach ($catalog->secrets() as $secret) {
            self::assertStringStartsWith('integration.', $secret->key);
            self::assertStringNotContainsString('=', $secret->secretName);
            self::assertGreaterThanOrEqual(8, $secret->maxLength);
        }
    }
}
