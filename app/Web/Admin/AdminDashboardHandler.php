<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\AdminInformationArchitectureService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AdminDashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminInformationArchitectureService $administration,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($actor, $request);
            }

            $search = $this->searchFromQuery($request);
            $snapshot = $this->administration->dashboard($actor, $search);
            $content = AdminDashboardHtml::page($snapshot, $this->basePath, $csrf);

            return Response::html(ProfileHtml::page(
                'Administration',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(\Forwext\Core\Domain\Entity\EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        $navigationKey = $body['navigation_key'] ?? null;
        if (!is_string($action) || !is_string($navigationKey)) {
            throw new InvalidArgumentException('ACP navigation action is invalid.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($action === 'toggle_favorite') {
            $this->administration->toggleFavorite($actor, $navigationKey, $now);
            $search = $body['search'] ?? '';
            if (!is_string($search) || strlen($search) > 80 || preg_match('//u', $search) !== 1) {
                throw new InvalidArgumentException('ACP return search is invalid.');
            }
            $target = $this->basePath->prepend('/admin');
            $search = trim($search);
            if ($search !== '') {
                $target .= '?q=' . rawurlencode($search);
            }

            return Response::redirect($target, 303)->withHeader('Cache-Control', 'no-store');
        }

        if ($action === 'open') {
            $path = $this->administration->targetAndRecordRecent($actor, $navigationKey, $now);

            return Response::redirect($this->basePath->prepend($path), 303)
                ->withHeader('Cache-Control', 'no-store');
        }

        throw new InvalidArgumentException('Unknown ACP navigation action.');
    }

    private function searchFromQuery(Request $request): string
    {
        $value = $request->query()['q'] ?? '';
        if (!is_string($value) || strlen($value) > 80 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('ACP search query is invalid.');
        }

        return $value;
    }
}
