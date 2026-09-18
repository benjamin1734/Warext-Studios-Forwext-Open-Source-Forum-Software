<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Routing\Router;

final readonly class BugDiagnosticContextCollector
{
    public const ATTRIBUTE_THEME_KEY = 'theme_key';
    public const ATTRIBUTE_MODULE_KEY = 'module_key';

    public function __construct(private BugBrowserDeviceClassifier $clientClassifier)
    {
    }

    public function collect(
        Request $request,
        EntityId $reportId,
        ?EntityId $actorUserId,
        ?DateTimeImmutable $now = null,
    ): BugDiagnosticContext {
        $routeName = $this->routeName($request);
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        if (!is_array($parameters)) {
            $parameters = [];
        }

        $themeKey = $request->attribute(self::ATTRIBUTE_THEME_KEY, 'default');
        $themeKey = is_string($themeKey) && preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $themeKey) === 1
            ? $themeKey
            : 'default';

        $moduleKey = $request->attribute(self::ATTRIBUTE_MODULE_KEY);
        if (!is_string($moduleKey) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $moduleKey) !== 1) {
            $moduleKey = $this->moduleFromRoute($routeName);
        }

        $requestId = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
        if (!is_string($requestId)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $requestId) !== 1
        ) {
            $requestId = null;
        }

        return new BugDiagnosticContext(
            $reportId,
            $actorUserId,
            $this->safePath($request->uri()),
            $routeName,
            $this->firstEntityId($parameters, ['forumId','forumNodeId','nodeId']),
            $this->firstEntityId($parameters, ['threadId']),
            $this->firstEntityId($parameters, ['postId']),
            $themeKey,
            $moduleKey,
            $this->clientClassifier->classify($request->headers()->first('user-agent')),
            $requestId,
            ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')),
        );
    }

    private function safePath(string $uri): string
    {
        $path = explode('?', $uri, 2)[0];
        if ($path === '' || !str_starts_with($path, '/')) {
            return '/';
        }
        if (strlen($path) > 2048) {
            return substr($path, 0, 2048);
        }
        return $path;
    }

    private function routeName(Request $request): ?string
    {
        $value = $request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) === 1
            ? $value
            : null;
    }

    private function moduleFromRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        $candidate = strtolower(explode('.', $routeName, 2)[0]);
        return preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $candidate) === 1 ? $candidate : null;
    }

    /**
     * @param array<string,mixed> $parameters
     * @param list<string> $keys
     */
    private function firstEntityId(array $parameters, array $keys): ?EntityId
    {
        foreach ($keys as $key) {
            $value = $parameters[$key] ?? null;
            if (is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1) {
                return EntityId::fromString($value);
            }
        }
        return null;
    }
}
