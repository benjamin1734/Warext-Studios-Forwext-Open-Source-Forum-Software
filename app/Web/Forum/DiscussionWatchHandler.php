<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\State\DiscussionStateRepository;
use Forwext\Core\Forum\State\DiscussionStateException;
use Forwext\Core\Forum\State\DiscussionStateService;
use Forwext\Core\Forum\State\WatchNotificationMode;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class DiscussionWatchHandler implements RequestHandlerInterface
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private DiscussionStateRepository $state,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
        private bool $threadTarget,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Unauthorized', 401)->withHeader('Cache-Control', 'no-store');
        }

        $modeRaw = $request->parsedBody()['mode'] ?? null;
        $mode = is_string($modeRaw) ? WatchNotificationMode::tryFrom($modeRaw) : null;
        if ($mode === null) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }

        $service = new DiscussionStateService(
            $this->nodes,
            $this->threads,
            $this->state,
            new PermissionGate($this->authorizer, $actor),
        );

        try {
            if ($this->threadTarget) {
                $threadId = $this->threadId($request);
                if ($threadId === null) {
                    return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
                }
                $service->watchThread($threadId, $mode, new DateTimeImmutable('now', new DateTimeZone('UTC')));

                return Response::redirect(
                    $this->basePath->prepend('/threads/' . rawurlencode($threadId->value())) . '?watch=updated',
                    303,
                );
            }

            $slug = $this->slug($request);
            if ($slug === null) {
                return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
            }
            $forum = $this->nodes->findBySlug($slug);
            if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
                return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
            }

            $service->watchForum($forum->id(), $mode, new DateTimeImmutable('now', new DateTimeZone('UTC')));

            return Response::redirect(
                $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value())) . '?watch=updated',
                303,
            );
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (DiscussionStateException|InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function threadId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $raw = is_array($parameters) ? ($parameters['threadId'] ?? null) : null;

        return is_string($raw) && preg_match('/^[a-f0-9]{32}$/D', $raw) === 1
            ? EntityId::fromString($raw)
            : null;
    }

    private function slug(Request $request): ?string
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $raw = is_array($parameters) ? ($parameters['slug'] ?? null) : null;

        return is_string($raw) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $raw) === 1
            ? $raw
            : null;
    }
}
