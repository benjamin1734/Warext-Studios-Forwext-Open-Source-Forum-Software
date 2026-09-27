<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostOperationException;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Post\PostService;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class ThreadReplyHandler implements RequestHandlerInterface
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private PostRepository $posts,
        private ThreadTypeRegistry $types,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private ContentPipeline $pipeline,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $thread = $this->thread($request);
        if ($thread === null || $thread->moderationState() !== ThreadModerationState::Visible) {
            return Response::text('Not Found', 404);
        }

        $forum = $this->nodes->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            return Response::text('Not Found', 404);
        }

        $gate = new PermissionGate($this->authorizer, $actor);
        try {
            $hierarchy = new ForumNodeHierarchy($this->nodes->all());
            (new ForumNodeAuthorization($gate))->requireView($hierarchy, $forum->id());
            $gate->require(PostPermission::Create->key(), $forum->id());
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }

        $settings = $forum->forumSettings();
        if ($thread->isLocked() || $settings === null || !$settings->allowReplies()
            || !$this->types->require($thread->typeKey())->allowsReplies()
        ) {
            return Response::text('Replies are unavailable.', 409)->withHeader('Cache-Control', 'no-store');
        }

        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request, $forum, $thread, $actor, $gate);
        }

        return $this->view($request, $forum, $thread, $actor, false);
    }

    private function submit(
        Request $request,
        ForumNode $forum,
        Thread $thread,
        EntityId $actor,
        PermissionGate $gate,
    ): Response {
        $message = $request->parsedBody()['body'] ?? null;

        try {
            if (!is_string($message)) {
                throw new InvalidArgumentException('Reply form is incomplete.');
            }

            $post = (new PostService(
                $this->nodes,
                $this->threads,
                $this->types,
                $this->posts,
                $gate,
                pipeline: $this->pipeline,
            ))->reply(
                $thread->id(),
                PostBody::fromString($message),
                $this->now(),
            );

            $suffix = $post->moderationState() === PostModerationState::Visible
                ? '#post-' . rawurlencode($post->id()->value())
                : '?reply_pending=1';

            return Response::text('', 303)
                ->withHeader(
                    'Location',
                    $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value())) . $suffix,
                )
                ->withHeader('Cache-Control', 'no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|PostOperationException) {
            return $this->view($request, $forum, $thread, $actor, true, is_string($message) ? $message : '');
        }
    }

    private function view(
        Request $request,
        ForumNode $forum,
        Thread $thread,
        EntityId $actor,
        bool $error,
        string $message = '',
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $threadUrl = $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()));
        $action = $threadUrl . '/reply';

        $html = '<section class="forum-compose"><div class="forum-compose-head"><div>'
            . '<span class="forum-eyebrow">YANITLA</span><h1>' . self::e($thread->title()->value()) . '</h1>'
            . '<p>' . self::e($forum->title()) . ' forumundaki konuya yanıt gönderiyorsun.</p>'
            . '</div><a class="fx-btn" href="' . self::e($threadUrl) . '">Konuya dön</a></div>'
            . ($error ? '<div class="forum-compose-error">Yanıt gönderilemedi. Mesaj alanını kontrol edip tekrar deneyin.</div>' : '')
            . '<form class="forum-compose-form card" method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($token) . '">'
            . '<label><span>Mesaj</span><textarea name="body" rows="12" maxlength="100000" required>'
            . self::e($message) . '</textarea></label>'
            . '<div class="forum-compose-actions"><a class="fx-btn" href="' . self::e($threadUrl) . '">İptal</a>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Yanıtı gönder</button></div></form></section>';

        return Response::html(ProfileHtml::page(
            'Yanıtla · ' . $thread->title()->value(),
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Forumlar', '/forums'),
                new BreadcrumbItem($forum->title(), '/forums/' . $forum->slug()->value()),
                new BreadcrumbItem($thread->title()->value(), '/threads/' . $thread->id()->value()),
                new BreadcrumbItem('Yanıtla'),
            ]),
            authenticated: true,
            viewerId: $actor->value(),
        ), $error ? 422 : 200)->withHeader('Cache-Control', 'private, no-store');
    }

    private function thread(Request $request): ?Thread
    {
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($params) ? ($params['threadId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }

        return $this->threads->find(EntityId::fromString($value));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
