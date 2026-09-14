<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EntityIdTest extends TestCase
{
    public function testStringAndNumericEntityIdsAreStableValueObjects(): void
    {
        $left = EntityId::fromString('thread:42');
        $right = EntityId::fromString('thread:42');

        self::assertTrue($left->equals($right));
        self::assertSame('thread:42', $left->value());
        self::assertSame('42', EntityId::fromInt(42)->value());
    }

    public function testUnsafeEntityIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EntityId::fromString("bad\nvalue");
    }
}
