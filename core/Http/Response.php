<?php

declare(strict_types=1);

namespace Forwext\Core\Http;

use Forwext\Core\Http\Cookie\ResponseCookie;
use JsonException;

final class Response
{
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private HeaderBag $headers = new HeaderBag(),
    ) {
        self::assertStatus($status);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, new HeaderBag(['Content-Type' => 'text/plain; charset=utf-8']));
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, new HeaderBag(['Content-Type' => 'text/html; charset=utf-8']));
    }

    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $body = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new HttpException('Unable to encode JSON response.', previous: $exception);
        }

        return new self($body, $status, new HeaderBag(['Content-Type' => 'application/json; charset=utf-8']));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): HeaderBag
    {
        return $this->headers;
    }

    public function withStatus(int $status): self
    {
        self::assertStatus($status);
        $copy = clone $this;
        $copy->status = $status;
        return $copy;
    }

    public function withHeader(string $name, string|array $values): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->with($name, $values);
        return $copy;
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->headers = $this->headers->appended($name, $value);
        return $copy;
    }

    public function withCookie(ResponseCookie $cookie): self
    {
        return $this->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
    }

    private static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new HttpException('HTTP response status must be between 100 and 599.');
        }
    }
}
