<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Social\Interaction;

use Forwext\Core\Social\Interaction\ReactionType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReactionTypeTest extends TestCase
{
    public function testReactionTypeTrimsLabelAndAcceptsBoundedScore(): void
    {
        $type = new ReactionType('helpful', ' Helpful ', -2);
        self::assertSame('helpful', $type->key);
        self::assertSame('Helpful', $type->label);
        self::assertSame(-2, $type->score);
    }

    public function testReactionTypeRejectsUnsafeLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReactionType('bad', "Bad\x01Label", 0);
    }
}
