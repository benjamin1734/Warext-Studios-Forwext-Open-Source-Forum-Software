<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use PHPUnit\Framework\TestCase;

final class CoreMigrationRegistryTest extends TestCase
{
    public function testInstallerMigrationIdsAreValidUniqueAndChronological(): void
    {
        $ids = [];

        foreach (CoreMigrationRegistry::all() as $migration) {
            $ids[] = $migration->id()->value();
        }

        self::assertNotEmpty($ids);
        self::assertCount(count($ids), array_unique($ids));

        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids, 'Core migrations must be registered in chronological id order.');
    }
}
