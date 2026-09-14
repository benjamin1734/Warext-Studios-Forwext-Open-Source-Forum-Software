<?php

declare(strict_types=1);

namespace Forwext\Core\Config;

use JsonException;

final readonly class EnvironmentConfigSource
{
    public function __construct(private string $prefix = 'FORWEXT_CONFIG__')
    {
    }

    /**
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    public function load(?array $variables = null): array
    {
        if ($variables === null) {
            $process = getenv();
            $variables = is_array($process) ? $process : [];
        }

        $config = [];

        foreach ($variables as $name => $rawValue) {
            if (!str_starts_with($name, $this->prefix) || !is_scalar($rawValue)) {
                continue;
            }

            $suffix = substr($name, strlen($this->prefix));
            if ($suffix === '') {
                throw new ConfigException(sprintf('Environment variable "%s" has no configuration path.', $name));
            }

            $segments = array_map('strtolower', explode('__', $suffix));
            foreach ($segments as $segment) {
                if ($segment === '' || preg_match('/^[a-z0-9_]+$/', $segment) !== 1) {
                    throw new ConfigException(sprintf('Environment variable "%s" contains an invalid path segment.', $name));
                }
            }

            $this->assign($config, $segments, $this->decode((string) $rawValue), $name);
        }

        return $config;
    }

    private function decode(string $value): mixed
    {
        $trimmed = trim($value);
        $lower = strtolower($trimmed);

        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            preg_match('/^-?(0|[1-9][0-9]*)$/', $trimmed) === 1 => (int) $trimmed,
            preg_match('/^-?(?:[0-9]+\.[0-9]+|[0-9]+[eE][+-]?[0-9]+)$/', $trimmed) === 1 => (float) $trimmed,
            str_starts_with($trimmed, 'json:') => $this->decodeJson(substr($trimmed, 5)),
            default => $value,
        };
    }

    private function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigException('Invalid json: value in Forwext environment configuration.', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param non-empty-list<string> $segments
     */
    private function assign(array &$config, array $segments, mixed $value, string $source): void
    {
        $cursor =& $config;
        $lastIndex = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $cursor[$segment] = $value;
                return;
            }

            if (array_key_exists($segment, $cursor) && !is_array($cursor[$segment])) {
                throw new ConfigException(sprintf(
                    'Environment variable "%s" conflicts with an existing scalar configuration path.',
                    $source,
                ));
            }

            if (!array_key_exists($segment, $cursor)) {
                $cursor[$segment] = [];
            }

            /** @var array<string, mixed> $next */
            $next =& $cursor[$segment];
            $cursor =& $next;
        }
    }
}
