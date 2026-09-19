<?php

declare(strict_types=1);

namespace Forwext\App\Web\Portfolio;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class PortfolioProjectHandler implements RequestHandlerInterface
{
    public function __construct(
        private PortfolioService $portfolio,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $projectId = $this->projectId($request);
        if ($projectId === null) {
            return Response::text('Not Found', 404);
        }
        $actor = $this->viewers->resolve($request);

        try {
            if ($request->method() === HttpMethod::Post) {
                if ($actor === null) {
                    return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
                }
                $body = $request->parsedBody();
                $action = $body['action'] ?? null;
                if (!is_string($action)) {
                    throw new InvalidArgumentException('Portfolio action is missing.');
                }
                if ($action === 'comment') {
                    $this->portfolio->addComment($actor, $projectId, self::required($body, 'body', 10000));
                } elseif ($action === 'react') {
                    $this->portfolio->react($actor, $projectId, self::required($body, 'reaction', 32));
                } elseif ($action === 'unreact') {
                    $this->portfolio->removeReaction($actor, $projectId);
                } else {
                    throw new InvalidArgumentException('Portfolio action is invalid.');
                }
                return Response::text('', 303)
                    ->withHeader('Location', $this->basePath->prepend(
                        '/portfolio/' . rawurlencode($projectId->value()) . '?updated=1',
                    ))
                    ->withHeader('Cache-Control', 'no-store');
            }

            $project = $this->portfolio->project($projectId, $actor);
            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            $csrf = is_string($token) && $token !== '' ? $token : null;
            return Response::html(PortfolioHtml::project(
                $project,
                $this->portfolio->comments($projectId, $actor),
                $this->portfolio->reactionSummary($projectId, $actor),
                $this->basePath,
                $actor !== null,
                $csrf,
                $actor !== null && $this->portfolio->canManageProject($actor, $project),
                ($request->query()['updated'] ?? null) === '1' ? 'İşlem kaydedildi.' : null,
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function projectId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['projectId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Portfolio field is missing.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Portfolio field is invalid.');
        }
        return $value;
    }
}
