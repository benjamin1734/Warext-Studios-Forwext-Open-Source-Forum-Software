<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

final readonly class PathTemplate
{
    /** @var list<array{type: 'static'|'parameter', value: string}> */
    private array $segments;

    /** @var array<string, string> */
    private array $requirements;

    /** @param array<string, string> $requirements */
    public function __construct(
        private string $template,
        array $requirements = [],
    ) {
        if ($template === '' || $template[0] !== '/' || str_contains($template, '?') || str_contains($template, '#')) {
            throw new RoutingException('Route template must be an absolute path without query or fragment.');
        }

        if ($template !== '/' && str_ends_with($template, '/')) {
            throw new RoutingException('Route templates may not have a trailing slash.');
        }

        if (str_contains($template, '//')) {
            throw new RoutingException('Route templates may not contain empty path segments.');
        }

        $this->segments = $this->parseSegments($template);
        $parameterNames = [];
        foreach ($this->segments as $segment) {
            if ($segment['type'] !== 'parameter') {
                continue;
            }

            if (isset($parameterNames[$segment['value']])) {
                throw new RoutingException(sprintf('Route parameter "%s" is declared more than once.', $segment['value']));
            }
            $parameterNames[$segment['value']] = true;
        }

        foreach ($requirements as $name => $requirement) {
            if (!isset($parameterNames[$name])) {
                throw new RoutingException(sprintf('Requirement provided for unknown route parameter "%s".', $name));
            }
            if ($requirement === '') {
                throw new RoutingException(sprintf('Requirement for route parameter "%s" cannot be empty.', $name));
            }
            if (@preg_match($this->requirementPattern($requirement), '') === false) {
                throw new RoutingException(sprintf('Invalid requirement regex for route parameter "%s".', $name));
            }
        }

        $this->requirements = $requirements;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function specificity(): int
    {
        $static = 0;
        foreach ($this->segments as $segment) {
            if ($segment['type'] === 'static') {
                ++$static;
            }
        }

        return ($static * 1000) + count($this->segments);
    }

    /** @return array<string, string>|null */
    public function match(string $path): ?array
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '?') || str_contains($path, '#')) {
            return null;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            return null;
        }

        $rawSegments = $path === '/' ? [] : explode('/', substr($path, 1));
        if (count($rawSegments) !== count($this->segments)) {
            return null;
        }

        $parameters = [];
        foreach ($this->segments as $index => $definition) {
            $decoded = $this->decodeSegment($rawSegments[$index]);
            if ($decoded === null) {
                return null;
            }

            if ($definition['type'] === 'static') {
                if (!hash_equals($definition['value'], $decoded)) {
                    return null;
                }
                continue;
            }

            $name = $definition['value'];
            $requirement = $this->requirements[$name] ?? null;
            if ($requirement !== null && preg_match($this->requirementPattern($requirement), $decoded) !== 1) {
                return null;
            }

            $parameters[$name] = $decoded;
        }

        return $parameters;
    }

    /** @param array<string, string|int> $parameters */
    public function generate(array $parameters = []): string
    {
        if ($this->segments === []) {
            if ($parameters !== []) {
                throw new RoutingException('Root route does not accept parameters.');
            }
            return '/';
        }

        $used = [];
        $generated = [];

        foreach ($this->segments as $definition) {
            if ($definition['type'] === 'static') {
                $generated[] = rawurlencode($definition['value']);
                continue;
            }

            $name = $definition['value'];
            if (!array_key_exists($name, $parameters)) {
                throw new RoutingException(sprintf('Missing value for route parameter "%s".', $name));
            }

            $value = (string) $parameters[$name];
            if (!$this->isSafeDecodedSegment($value)) {
                throw new RoutingException(sprintf('Route parameter "%s" contains an unsafe path value.', $name));
            }

            $requirement = $this->requirements[$name] ?? null;
            if ($requirement !== null && preg_match($this->requirementPattern($requirement), $value) !== 1) {
                throw new RoutingException(sprintf('Route parameter "%s" does not satisfy its requirement.', $name));
            }

            $generated[] = rawurlencode($value);
            $used[$name] = true;
        }

        foreach ($parameters as $name => $_value) {
            if (!isset($used[$name])) {
                throw new RoutingException(sprintf('Unknown route parameter "%s".', $name));
            }
        }

        return '/' . implode('/', $generated);
    }

    /** @return list<array{type: 'static'|'parameter', value: string}> */
    private function parseSegments(string $template): array
    {
        if ($template === '/') {
            return [];
        }

        $segments = [];
        foreach (explode('/', substr($template, 1)) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D', $segment, $matches) === 1) {
                $segments[] = ['type' => 'parameter', 'value' => $matches[1]];
                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new RoutingException('Route parameters must occupy an entire path segment.');
            }

            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $segment) === 1) {
                throw new RoutingException('Static route segment contains malformed percent encoding.');
            }

            $decoded = rawurldecode($segment);
            if (!$this->isSafeDecodedSegment($decoded)) {
                throw new RoutingException('Static route segment contains unsafe path data.');
            }

            $segments[] = ['type' => 'static', 'value' => $decoded];
        }

        return $segments;
    }

    private function decodeSegment(string $raw): ?string
    {
        if ($raw === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            return null;
        }

        $decoded = rawurldecode($raw);
        return $this->isSafeDecodedSegment($decoded) ? $decoded : null;
    }

    private function isSafeDecodedSegment(string $value): bool
    {
        return $value !== ''
            && $value !== '.'
            && $value !== '..'
            && !str_contains($value, '/')
            && !str_contains($value, '\\')
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function requirementPattern(string $requirement): string
    {
        return '~\A(?:' . str_replace('~', '\\~', $requirement) . ')\z~uD';
    }
}
