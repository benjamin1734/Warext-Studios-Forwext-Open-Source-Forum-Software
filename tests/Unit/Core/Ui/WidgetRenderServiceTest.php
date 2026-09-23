<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Cache\CacheEntry;
use Forwext\Core\Cache\CacheStore;
use Forwext\Core\Ui\Widget\WidgetContext;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use Forwext\Core\Ui\Widget\WidgetRenderService;
use PHPUnit\Framework\TestCase;

final class WidgetRenderServiceTest extends TestCase
{
    public function testCacheUsesHashedContextAndSlotTags(): void
    {
        $cache = new MemoryWidgetCacheStore();
        $registry = WidgetRegistry::withCoreDefaults();
        $service = new WidgetRenderService($registry, $cache);
        $context = new WidgetContext('Sensitive Profile Title', 'tr', true);

        $first = $service->renderSlot('footer.after', $context);
        $second = $service->renderSlot('footer.after', $context);

        self::assertSame($first, $second);
        self::assertSame(1, count($cache->values));
        $key = array_key_first($cache->values);
        self::assertIsString($key);
        self::assertStringStartsWith('ui-widget:', $key);
        self::assertStringNotContainsString('Sensitive Profile Title', $key);
        self::assertContains('ui.widget.core.brand-footer', $cache->tags[$key]);
        self::assertContains('ui.slot.footer.after', $cache->tags[$key]);
    }

    public function testDifferentContextProducesDifferentCacheEntry(): void
    {
        $cache = new MemoryWidgetCacheStore();
        $service = new WidgetRenderService(WidgetRegistry::withCoreDefaults(), $cache);

        $service->renderSlot('footer.after', new WidgetContext('Page A', 'tr', false));
        $service->renderSlot('footer.after', new WidgetContext('Page B', 'tr', false));

        self::assertCount(2, $cache->values);
    }
}

final class MemoryWidgetCacheStore implements CacheStore
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, list<string>> */
    public array $tags = [];

    public function get(string $key): ?CacheEntry
    {
        return isset($this->values[$key])
            ? new CacheEntry($this->values[$key], tags: $this->tags[$key] ?? [])
            : null;
    }

    public function put(string $key, string $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->values[$key] = $value;
        $this->tags[$key] = $tags;
    }

    public function delete(string $key): bool
    {
        $exists = isset($this->values[$key]);
        unset($this->values[$key], $this->tags[$key]);
        return $exists;
    }

    public function invalidateTag(string $tag): int
    {
        $removed = 0;
        foreach ($this->tags as $key => $tags) {
            if (in_array($tag, $tags, true)) {
                unset($this->values[$key], $this->tags[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
