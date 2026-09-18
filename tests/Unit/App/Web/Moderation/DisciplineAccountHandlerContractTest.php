<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use Forwext\App\Web\Moderation\DisciplineAccountHandler;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;

final class DisciplineAccountHandlerContractTest extends TestCase
{
    public function testDisciplineAccountHandlerSatisfiesRouteHandlerContract(): void
    {
        self::assertTrue(is_a(
            DisciplineAccountHandler::class,
            RequestHandlerInterface::class,
            true,
        ));
    }
}
