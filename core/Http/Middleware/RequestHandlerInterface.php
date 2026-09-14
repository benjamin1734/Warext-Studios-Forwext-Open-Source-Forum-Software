<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Middleware;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}
