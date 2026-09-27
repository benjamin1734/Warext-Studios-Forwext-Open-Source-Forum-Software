<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostOperationException;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Post\PostService;
use Forwext\Core\Forum\Post\ThreadPublishingService;
use Forwext\Core\Forum\Thread\ThreadCreationService;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadOperationException;
use Forwext\Core\Forum\Thread\ThreadPermission;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
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

final readonly class ThreadCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
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

        $forum = $this->forum($request);
        if ($forum === null) {
            return Response::text('Not Found', 404);
        }

        $gate = new PermissionGate($this->authorizer, $actor);
        try {
            $hierarchy = new ForumNodeHierarchy($this->nodes->all());
            (new ForumNodeAuthorization($gate))->requireView($hierarchy, $forum->id());
            $gate->require(ThreadPermission::Create->key(), $forum->id());
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }

        $settings = $forum->forumSettings();
        if ($settings === null || !$settings->allowNewThreads()) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }

        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request, $forum, $actor, $gate);
        }

        return $this->view($request, $forum, $actor, false);
    }

    private function submit(Request $request, ForumNode $forum, EntityId $actor, PermissionGate $gate): Response
    {
        $body = $request->parsedBody();
        $title = $body['title'] ?? null;
        $message = $body['body'] ?? null;

        try {
            if (!is_string($title) || !is_string($message)) {
                throw new InvalidArgumentException('Thread form is incomplete.');
            }

            $postService = new PostService(
                $this->nodes,
                $this->threads,
                $this->types,
                $this->posts,
                $gate,
                pipeline: $this->pipeline,
            );
            $creator = new ThreadCreationService(
                $this->nodes,
                $this->threads,
                $this->types,
                $gate,
                pipeline: $this->pipeline,
            );
            $published = (new ThreadPublishingService(
                $this->database,
                $creator,
                $postService,
            ))->publish(
                $forum->id(),
                ThreadTypeKey::fromString('discussion'),
                ThreadTitle::fromString($title),
                PostBody::fromString($message),
                $this->now(),
            );

            if ($published->thread->moderationState() !== ThreadModerationState::Visible) {
                return Response::text('', 303)
                    ->withHeader(
                        'Location',
                        $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value()) . '?submitted=1'),
                    )
                    ->withHeader('Cache-Control', 'no-store');
            }

            return Response::text('', 303)
                ->withHeader(
                    'Location',
                    $this->basePath->prepend('/threads/' . rawurlencode($published->thread->id()->value())),
                )
                ->withHeader('Cache-Control', 'no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ThreadOperationException|PostOperationException) {
            return $this->view($request, $forum, $actor, true, is_string($title) ? $title : '', is_string($message) ? $message : '');
        }
    }

    private function view(
        Request $request,
        ForumNode $forum,
        EntityId $actor,
        bool $error,
        string $title = '',
        string $message = '',
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $forumUrl = $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value()));
        $action = $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value()) . '/new-thread');

        $html = '<section class="forum-compose"><div class="forum-compose-head"><div>'
            . '<span class="forum-eyebrow">YENİ KONU</span><h1>' . self::e($forum->title()) . '</h1>'
            . '<p>Başlığını net yaz, içeriği anlaşılır biçimde paylaş. Gönderim forum izinleri ve içerik denetiminden geçer.</p>'
            . '</div><a class="fx-btn" href="' . self::e($forumUrl) . '">Vazgeç</a></div>'
            . ($error ? '<div class="forum-compose-error">Konu oluşturulamadı. Başlık ve mesaj alanlarını kontrol edip tekrar deneyin.</div>' : '')
            . '<form class="forum-compose-form card" method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($token) . '">'
            . '<label><span>Konu başlığı</span><input type="text" name="title" maxlength="200" required autocomplete="off" value="'
            . self::e($title) . '"></label>'
            . '<label><span>Mesaj</span><textarea name="body" rows="12" maxlength="100000" required>'
            . self::e($message) . '</textarea></label>'
            . '<div class="forum-compose-actions"><a class="fx-btn" href="' . self::e($forumUrl) . '">İptal</a>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Konuyu oluştur</button></div></form></section>';

        return Response::html(ProfileHtml::page(
            'Yeni konu',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Forumlar', '/forums'),
                new BreadcrumbItem($forum->title(), '/forums/' . $forum->slug()->value()),
                new BreadcrumbItem('Yeni konu'),
            ]),
            authenticated: true,
            viewerId: $actor->value(),
        ), $error ? 422 : 200)->withHeader('Cache-Control', 'private, no-store');
    }

    private function forum(Request $request): ?ForumNode
    {
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($params) ? ($params['slug'] ?? null) : null;
        if (!is_string($value)) {
            return null;
        }

        try {
            $node = $this->nodes->findBySlug(ForumNodeSlug::fromString($value));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $node !== null && $node->type() === ForumNodeType::Forum ? $node : null;
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
