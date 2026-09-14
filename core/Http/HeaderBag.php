<?php

declare(strict_types=1);

namespace Forwext\Core\Http;

final class HeaderBag
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @param array<string, string|list<string>> $headers */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $this->appendInternal($name, $value);
            }
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($this->normalizeName($name), $this->headers);
    }

    /** @return list<string> */
    public function get(string $name): array
    {
        return $this->headers[$this->normalizeName($name)] ?? [];
    }

    public function line(string $name): ?string
    {
        $values = $this->get($name);
        return $values === [] ? null : implode(', ', $values);
    }

    public function first(string $name): ?string
    {
        return $this->get($name)[0] ?? null;
    }

    public function with(string $name, string|array $values): self
    {
        $copy = clone $this;
        $normalized = $this->normalizeName($name);
        $copy->headers[$normalized] = [];

        foreach (is_array($values) ? $values : [$values] as $value) {
            if (!is_string($value)) {
                throw new HttpException('HTTP header values must be strings.');
            }
            $copy->appendInternal($name, $value);
        }

        return $copy;
    }

    public function appended(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->appendInternal($name, $value);
        return $copy;
    }

    public function without(string $name): self
    {
        $copy = clone $this;
        unset($copy->headers[$this->normalizeName($name)]);
        return $copy;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->headers;
    }

    private function appendInternal(string $name, string $value): void
    {
        $normalized = $this->normalizeName($name);
        $this->validateValue($value);
        $this->headers[$normalized] ??= [];
        $this->headers[$normalized][] = trim($value, " \t");
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
            throw new HttpException(sprintf('Invalid HTTP header name "%s".', $name));
        }

        return strtolower($name);
    }

    private function validateValue(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new HttpException('HTTP header values may not contain CR, LF or NUL characters.');
        }
    }
}
