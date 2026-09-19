<?php

declare(strict_types=1);

namespace Forwext\App\Web\Audit;

use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;

final class HttpAuditRequestId
{
    public static function fromRequest(Request $request): AuditRequestId
    {
        $value = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
        return is_string($value) && $value !== ''
            ? AuditRequestId::fromString($value)
            : AuditRequestId::generate();
    }
}
