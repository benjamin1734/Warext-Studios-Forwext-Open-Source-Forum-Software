<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http;

use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class HeaderBagTest extends TestCase
{
    public function testHeaderNamesAreCaseInsensitiveAndValuesCanBeAppended(): void
    {
        $headers = (new HeaderBag(['Content-Type' => 'text/plain']))
            ->appended('X-Test', 'one')
            ->appended('x-test', 'two');

        self::assertSame('text/plain', $headers->first('content-type'));
        self::assertSame(['one', 'two'], $headers->get('X-TEST'));
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(HttpException::class);
        new HeaderBag(['X-Test' => "safe\r\nX-Evil: injected"]);
    }
}
