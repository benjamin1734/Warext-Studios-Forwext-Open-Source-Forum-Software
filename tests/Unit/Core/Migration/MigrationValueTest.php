<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationScope;
use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationValueTest extends TestCase
{
    public function testOwnerScopesAndMigrationIdsAreCanonical(): void
    {
        $owner = MigrationOwner::addon('vendor.example-addon');
        $id = MigrationId::fromString('20260914001300_create_table');

        self::assertSame(MigrationScope::Addon, $owner->scope);
        self::assertSame('addon:vendor.example-addon', $owner->key());
        self::assertSame('20260914001300_create_table', $id->value());
    }

    public function testInvalidMigrationIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MigrationId::fromString('13-bad-migration');
    }

    public function testSemanticVersionComparisonSupportsPrereleases(): void
    {
        $alpha = SemanticVersion::parse('0.1.0-alpha.1');
        $stable = SemanticVersion::parse('0.1.0');

        self::assertTrue($stable->isGreaterThan($alpha));
        self::assertFalse($alpha->isGreaterThan($stable));
    }
}
