<?php

declare(strict_types=1);

namespace Forwext\App\Web;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Response;

final class ResponseEmitter
{
    public function emit(Response $response, HttpMethod $requestMethod): void
    {
        http_response_code($response->status());
        foreach ($response->headers()->all() as $name => $values) {
            foreach ($values as $index => $value) {
                header($name . ': ' . $value, $index === 0);
            }
        }

        if ($requestMethod !== HttpMethod::Head) {
            echo $response->body();
        }
    }
}
