<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Http\Response;

final class ApiV1ErrorResponder
{
    /** @param array<string,mixed> $details */
    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): Response {
        $error = ['code'=>$code,'message'=>$message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::json(['error'=>$error], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
