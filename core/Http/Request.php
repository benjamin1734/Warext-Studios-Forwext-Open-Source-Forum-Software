<?php

declare(strict_types=1);

namespace Forwext\Core\Http;

use Forwext\Core\Http\Upload\UploadNormalizer;
use JsonException;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $cookies
     * @param array<string, mixed> $uploads
     * @param array<string, mixed> $server
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private HttpMethod $method,
        private string $uri,
        private HeaderBag $headers = new HeaderBag(),
        private array $query = [],
        private array $parsedBody = [],
        private string $rawBody = '',
        private array $cookies = [],
        private array $uploads = [],
        private array $server = [],
        private array $attributes = [],
    ) {
        if ($uri === '' || str_contains($uri, "\0") || str_contains($uri, "\r") || str_contains($uri, "\n")) {
            throw new HttpException('Request URI is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null,
        ?string $rawBody = null,
    ): self {
        $server ??= $_SERVER;
        $query ??= $_GET;
        $post ??= $_POST;
        $cookies ??= $_COOKIE;
        $files ??= $_FILES;

        $methodValue = $server['REQUEST_METHOD'] ?? 'GET';
        $uriValue = $server['REQUEST_URI'] ?? '/';
        if (!is_string($methodValue) || !is_string($uriValue)) {
            throw new HttpException('Malformed server request metadata.');
        }

        if ($rawBody === null) {
            $read = file_get_contents('php://input');
            if ($read === false) {
                throw new HttpException('Unable to read request body.');
            }
            $rawBody = $read;
        }

        return new self(
            HttpMethod::parse($methodValue),
            $uriValue,
            self::headersFromServer($server),
            $query,
            $post,
            $rawBody,
            self::normalizeCookies($cookies),
            (new UploadNormalizer())->normalize($files),
            $server,
        );
    }

    public function method(): HttpMethod
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function parsedBody(): array
    {
        return $this->parsedBody;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @return array<string, mixed> */
    public function uploads(): array
    {
        return $this->uploads;
    }

    /** @return array<string, mixed> */
    public function server(): array
    {
        return $this->server;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        if ($name === '') {
            throw new HttpException('Request attribute name cannot be empty.');
        }

        $copy = clone $this;
        $copy->attributes[$name] = $value;
        return $copy;
    }

    public function contentType(): ?string
    {
        $line = $this->headers->first('content-type');
        if ($line === null) {
            return null;
        }

        return strtolower(trim(explode(';', $line, 2)[0]));
    }

    public function isJson(): bool
    {
        $type = $this->contentType();
        return $type !== null && ($type === 'application/json' || str_ends_with($type, '+json'));
    }

    public function json(int $maxBytes = 1_048_576): mixed
    {
        if ($maxBytes < 1) {
            throw new HttpException('JSON body size limit must be positive.');
        }

        if (!$this->isJson()) {
            throw new HttpException('Request Content-Type is not JSON.');
        }

        if (strlen($this->rawBody) > $maxBytes) {
            throw new HttpException('JSON request body exceeds the configured size limit.');
        }

        try {
            return json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpException('Request contains invalid JSON.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $server */
    private static function headersFromServer(array $server): HeaderBag
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        return new HeaderBag($headers);
    }

    /**
     * @param array<string, mixed> $cookies
     * @return array<string, string>
     */
    private static function normalizeCookies(array $cookies): array
    {
        $normalized = [];
        foreach ($cookies as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new HttpException('Malformed request cookie collection.');
            }

            if ($name === '' || str_contains($name, "\0") || str_contains($value, "\0")) {
                throw new HttpException('Malformed request cookie value.');
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }
}
