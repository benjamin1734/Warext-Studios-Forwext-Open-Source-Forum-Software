<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\State\DatabaseDiscussionStateRepository;
use Forwext\Core\Forum\State\DiscussionStateException;
use Forwext\Core\Forum\State\DiscussionStateService;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class ForumMarkReadHandler implements RequestHandlerInterface
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private DatabaseDiscussionStateRepository $state,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Unauthorized', 401)->withHeader('Cache-Control', 'no-store');
        }

        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $slug = is_array($parameters) ? ($parameters['slug'] ?? null) : null;
        if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        $forum = $this->nodes->findBySlug($slug);
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            (new DiscussionStateService(
                $this->nodes,
                $this->threads,
                $this->state,
                new PermissionGate($this->authorizer, $actor),
            ))->markForumRead(
                $forum->id(),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (DiscussionStateException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }

        return Response::redirect(
            $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value())) . '?read=marked',
            303,
        );
    }
}
