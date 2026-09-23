<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Navigation\AdminNavigationPreferences;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdminNavigationPreferencesTest extends TestCase
{
    public function testFavoriteToggleIsDeterministicAndBounded(): void
    {
        $preferences = new AdminNavigationPreferences();
        for ($index = 0; $index < 20; ++$index) {
            $preferences = $preferences->toggledFavorite('admin.tool-' . $index);
        }

        self::assertCount(12, $preferences->favorites());
        self::assertSame('admin.tool-19', $preferences->favorites()[0]);

        $preferences = $preferences->toggledFavorite('admin.tool-19');
        self::assertFalse(in_array('admin.tool-19', $preferences->favorites(), true));
    }

    public function testRecentItemsAreUniqueNewestFirstAndBounded(): void
    {
        $preferences = new AdminNavigationPreferences();
        for ($index = 0; $index < 15; ++$index) {
            $preferences = $preferences->recordedRecent('admin.area-' . $index);
        }
        $preferences = $preferences->recordedRecent('admin.area-12');

        self::assertCount(10, $preferences->recent());
        self::assertSame('admin.area-12', $preferences->recent()[0]);
        self::assertSame(
            1,
            count(array_filter(
                $preferences->recent(),
                static fn (string $key): bool => $key === 'admin.area-12',
            )),
        );
    }

    public function testPermissionFilterRemovesStaleOrNoLongerAccessibleKeys(): void
    {
        $preferences = new AdminNavigationPreferences(
            ['admin.appearance', 'admin.payments'],
            ['admin.payments', 'admin.appearance'],
        );

        $filtered = $preferences->filtered(['admin.appearance'=>true]);

        self::assertSame(['admin.appearance'], $filtered->favorites());
        self::assertSame(['admin.appearance'], $filtered->recent());
    }

    public function testInvalidNavigationKeyFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AdminNavigationPreferences(['https://example.com']);
    }
}
