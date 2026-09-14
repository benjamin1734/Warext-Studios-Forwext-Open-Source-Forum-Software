<?php

declare(strict_types=1);

namespace Forwext\Core\Http;

enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';

    public static function parse(string $method): self
    {
        return self::tryFrom(strtoupper(trim($method)))
            ?? throw new HttpException(sprintf('Unsupported HTTP method "%s".', $method));
    }

    public function isSafe(): bool
    {
        return $this === self::Get || $this === self::Head || $this === self::Options;
    }
}
