<?php

declare(strict_types=1);

namespace Forwext\App\Web\EasterEgg;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\EasterEgg\EasterEggRuntimeContext;
use Forwext\Core\EasterEgg\EasterEggService;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Throwable;

final readonly class EasterEggMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EasterEggService $easterEggs,
        private ProfileViewerResolver $viewers,
        private UserAccessAssignmentProvider $assignments,
        private BasePath $basePath,
        private EasterEggRenderer $renderer = new EasterEggRenderer(),
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        try {
            if (!$this->decoratable($request, $response)) {
                return $response;
            }

            $routeName = $request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
            if (!is_string($routeName) || $routeName === '') {
                return $response;
            }
            if ($routeName === 'easteregg.manage') {
                return $response;
            }

            $path = parse_url($request->uri(), PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                return $response;
            }
            $relativePath = $this->basePath->strip($path);
            if ($relativePath === null) {
                return $response;
            }

            $queryToken = $request->query()['egg'] ?? null;
            if (!is_string($queryToken) || preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $queryToken) !== 1) {
                $queryToken = null;
            }

            $groups = [];
            $viewer = $this->viewers->resolve($request);
            if ($viewer !== null) {
                $assignment = $this->assignments->find($viewer);
                if ($assignment !== null) {
                    $groups = array_merge([$assignment->primaryGroupId()], $assignment->secondaryGroupIds());
                }
            }

            $definitions = $this->easterEggs->activeFor(new EasterEggRuntimeContext(
                $routeName,
                $relativePath,
                $queryToken,
                $groups,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ));
            $markup = $this->renderer->render($definitions);
            if ($markup === '') {
                return $response;
            }

            $body = $response->body();
            $position = strripos($body, '</body>');
            if ($position === false) {
                return $response;
            }
            $body = substr($body, 0, $position) . $markup . substr($body, $position);

            return new Response($body, $response->status(), $response->headers());
        } catch (Throwable) {
            // Easter eggs are decorative. Runtime failure must never break the underlying forum response.
            return $response;
        }
    }

    private function decoratable(Request $request, Response $response): bool
    {
        if ($request->method()->value !== 'GET' && $request->method()->value !== 'HEAD') {
            return false;
        }
        if ($response->status() !== 200) {
            return false;
        }
        $contentType = strtolower($response->headers()->first('content-type') ?? '');
        return str_starts_with($contentType, 'text/html');
    }
}
