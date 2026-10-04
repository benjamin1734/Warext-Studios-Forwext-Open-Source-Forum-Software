<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\State\DatabaseDiscussionStateRepository;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class WatchedContentHandler implements RequestHandlerInterface
{
    public function __construct(
        private DatabaseDiscussionStateRepository $state,
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
        private DateTimeZone $timezone,
        private bool $threadList,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::html(WatchedContentHtml::authenticationRequired($this->basePath), 401)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $authorization = new ForumNodeAuthorization(new PermissionGate($this->authorizer, $actor));
        $rows = [];

        if ($this->threadList) {
            foreach ($this->state->watchedThreads($actor, 100) as $record) {
                $thread = $this->threads->find($record->targetId);
                if ($thread === null || $thread->moderationState() !== ThreadModerationState::Visible) {
                    continue;
                }
                $forum = $hierarchy->find($thread->forumNodeId());
                if (
                    $forum === null
                    || $forum->type() !== ForumNodeType::Forum
                    || !$authorization->canView($hierarchy, $forum->id())
                ) {
                    continue;
                }
                $rows[] = [
                    'title' => $thread->title()->value(),
                    'subtitle' => $forum->title(),
                    'href' => $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value())),
                    'mode' => $record->notificationMode,
                    'updated_at' => $record->updatedAt,
                ];
            }
        } else {
            foreach ($this->state->watchedForums($actor, 100) as $record) {
                $forum = $hierarchy->find($record->targetId);
                if (
                    $forum === null
                    || $forum->type() !== ForumNodeType::Forum
                    || !$authorization->canView($hierarchy, $forum->id())
                ) {
                    continue;
                }
                $rows[] = [
                    'title' => $forum->title(),
                    'subtitle' => $forum->description(),
                    'href' => $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value())),
                    'mode' => $record->notificationMode,
                    'updated_at' => $record->updatedAt,
                ];
            }
        }

        return Response::html(WatchedContentHtml::page(
            $rows,
            $this->threadList,
            $this->basePath,
            $this->timezone,
        ))->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }
}
