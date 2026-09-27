<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Discovery\DatabaseForumPublicReader;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class ThreadViewHandler implements RequestHandlerInterface
{
    private const VIEW_PERMISSION = 'forum.view';

    public function __construct(
        private ThreadRepository $threads,
        private ForumNodeRepository $nodes,
        private DatabaseForumPublicReader $reader,
        private EditorPreviewService $preview,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $threadId = $this->threadId($request);
        if ($threadId === null) {
            return Response::text('Not Found', 404);
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null || $thread->moderationState() !== ThreadModerationState::Visible) {
            return Response::text('Not Found', 404);
        }
        $forum = $this->nodes->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            return Response::text('Not Found', 404);
        }

        $actor = $this->viewers->resolve($request);
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        if (!$this->canView($actor, $hierarchy, $forum)) {
            return Response::text($actor === null ? 'Not Found' : 'Forbidden', $actor === null ? 404 : 403)
                ->withHeader('Cache-Control', 'no-store');
        }

        $page = $this->page($request);
        $posts = $this->reader->posts($threadId, $page, 20);
        if ($page > $posts['pages'] && $posts['total'] > 0) {
            return Response::text('Not Found', 404);
        }

        $body = '<section class="thread-view-head"><div><div class="thread-badges">'
            . ($thread->isSticky() ? '<span class="thread-badge">Sabit</span>' : '')
            . ($thread->isFeatured() ? '<span class="thread-badge thread-badge--accent">Öne çıkan</span>' : '')
            . ($thread->isLocked() ? '<span class="thread-badge">Kilitli</span>' : '')
            . '</div><h1>' . self::e($thread->title()->value()) . '</h1><p>'
            . number_format(max(0, $posts['total'] - 1), 0, ',', '.') . ' yanıt · '
            . number_format($posts['total'], 0, ',', '.') . ' mesaj</p></div>'
            . '<div class="thread-view-actions">';
        if ($this->canReply($actor, $thread, $forum)) {
            $body .= '<a class="fx-btn fx-btn--primary" href="'
                . self::e($this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()) . '/reply'))
                . '">Yanıtla</a>';
        }
        $body .= '<a class="fx-btn" href="' . self::e($this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value())))
            . '">Foruma dön</a></div></section>';

        if (($request->query()['reply_pending'] ?? null) === '1') {
            $body .= '<div class="forum-notice">Yanıtınız gönderildi ve moderasyon onayı bekliyor.</div>';
        }

        if ($posts['rows'] === []) {
            $body .= '<section class="card forum-empty-state"><h2>Görüntülenebilir mesaj yok</h2></section>';
        } else {
            $body .= '<div class="thread-post-list">';
            foreach ($posts['rows'] as $post) {
                $body .= $this->renderPost($post, $actor);
            }
            $body .= '</div>' . $this->pagination($thread, $posts['page'], $posts['pages']);
        }

        return Response::html(ProfileHtml::page(
            $thread->title()->value(),
            $body,
            $this->basePath,
            breadcrumbs: $this->breadcrumbs($hierarchy, $forum, $thread),
            authenticated: $actor !== null,
            viewerId: $actor?->value(),
        ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=30' : 'private, no-store');
    }

    private function canReply(?EntityId $actor, Thread $thread, ForumNode $forum): bool
    {
        $settings = $forum->forumSettings();
        return $actor !== null
            && !$thread->isLocked()
            && $settings !== null
            && $settings->allowReplies()
            && $this->authorizer->allows($actor, PostPermission::Create->key(), $forum->id());
    }

    private function canView(?EntityId $actor, ForumNodeHierarchy $hierarchy, ForumNode $forum): bool
    {
        if (!$hierarchy->isResolvable($forum->id())) {
            return false;
        }
        if ($actor === null) {
            return $hierarchy->isDiscoverable($forum->id());
        }

        return $this->authorizer->allows(
            $actor,
            PermissionKey::fromString(self::VIEW_PERMISSION),
            $forum->id(),
        );
    }

    /** @param array{post_id:string,position:int,body_source:string,created_at:string,updated_at:string,author_user_id:?string,author_username:?string} $post */
    private function renderPost(array $post, ?EntityId $actor): string
    {
        $username = $post['author_username'] ?? 'Silinmiş üye';
        $profileUrl = $post['author_username'] === null
            ? null
            : $this->basePath->prepend('/members/' . rawurlencode($post['author_username']));
        $initial = self::initial($username);

        try {
            $content = $this->preview->preview($post['body_source'])->html;
        } catch (InvalidArgumentException) {
            $content = '<div class="fx-rich-text">' . nl2br(self::e($post['body_source'])) . '</div>';
        }

        $author = $profileUrl === null
            ? '<strong>' . self::e($username) . '</strong>'
            : '<a class="thread-post-author-name" href="' . self::e($profileUrl) . '">' . self::e($username) . '</a>';

        return '<article class="thread-post" id="post-' . self::e($post['post_id']) . '">'
            . '<aside class="thread-post-author"><div class="thread-post-avatar">' . self::e($initial) . '</div>'
            . $author . '<span class="muted">Üye</span></aside>'
            . '<div class="thread-post-body"><header><a href="#post-' . self::e($post['post_id']) . '">#'
            . number_format($post['position'], 0, ',', '.') . '</a><time>'
            . self::e(self::date($post['created_at'])) . '</time></header>'
            . '<div class="thread-post-content">' . $content . '</div>'
            . '<footer><span class="muted">Son düzenleme: ' . self::e(self::date($post['updated_at'])) . '</span>'
            . $this->interactionControls($post, $actor) . '</footer>'
            . '</div></article>';
    }

    /** @param array{post_id:string,position:int,body_source:string,created_at:string,updated_at:string,author_user_id:?string,author_username:?string} $post */
    private function interactionControls(array $post, ?EntityId $actor): string
    {
        if ($actor === null) {
            return '';
        }

        $postId = self::e($post['post_id']);
        $ownPost = $post['author_user_id'] !== null && hash_equals($actor->value(), $post['author_user_id']);
        $reactions = '';
        if (!$ownPost) {
            foreach ([
                'like' => ['👍', 'Beğen'],
                'love' => ['❤️', 'Sevgi'],
                'haha' => ['😄', 'Haha'],
                'wow' => ['😮', 'Vay'],
                'sad' => ['😢', 'Üzgün'],
                'angry' => ['😠', 'Kızgın'],
            ] as $key => [$icon, $label]) {
                $reactions .= '<button type="button" class="thread-reaction-option" data-reaction-key="'
                    . self::e($key) . '" aria-label="' . self::e($label) . '">'
                    . self::e($icon) . '<span>' . self::e($label) . '</span></button>';
            }
            $reactions .= '<button type="button" class="thread-reaction-remove" data-remove-reaction>Tepkiyi kaldır</button>';
        } else {
            $reactions = '<span class="muted thread-own-reaction-note">Kendi mesajına tepki veremezsin.</span>';
        }

        return '<div class="thread-post-interactions" data-thread-interactions data-post-id="' . $postId . '">'
            . '<details class="thread-reaction-menu" data-reaction-menu><summary class="fx-btn">'
            . 'Tepkiler <span class="thread-reaction-total" data-reaction-total></span></summary>'
            . '<div class="thread-reaction-popover"><div class="thread-reaction-counts" data-reaction-counts></div>'
            . '<div class="thread-reaction-options">' . $reactions . '</div></div></details>'
            . '<details class="thread-bookmark-menu"><summary class="fx-btn">Yer imi</summary>'
            . '<form class="thread-bookmark-form" data-bookmark-form>'
            . '<label><span>Özel not</span><input name="note" maxlength="1000" autocomplete="off" '
            . 'placeholder="Yalnızca sen görürsün"></label>'
            . '<div><button class="fx-btn fx-btn--primary" type="submit">Kaydet</button>'
            . '<button class="fx-btn" type="button" data-remove-bookmark>Yer imini sil</button></div></form></details>'
            . '<span class="thread-interaction-status" data-interaction-status role="status" aria-live="polite"></span>'
            . '</div>';
    }

    private function pagination(Thread $thread, int $page, int $pages): string
    {
        if ($pages <= 1) {
            return '';
        }

        $html = '<nav class="pagination" aria-label="Konu mesaj sayfaları">';
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        for ($current = $start; $current <= $end; $current++) {
            $url = $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()))
                . ($current === 1 ? '' : '?page=' . $current);
            $html .= '<a href="' . self::e($url) . '"'
                . ($current === $page ? ' aria-current="page"' : '') . '>'
                . $current . '</a>';
        }

        return $html . '</nav>';
    }

    private function breadcrumbs(
        ForumNodeHierarchy $hierarchy,
        ForumNode $forum,
        Thread $thread,
    ): BreadcrumbTrail {
        $items = [
            new BreadcrumbItem('Ana Sayfa', '/'),
            new BreadcrumbItem('Forumlar', '/forums'),
        ];
        foreach ($hierarchy->breadcrumb($forum->id()) as $part) {
            if ($part->type() === ForumNodeType::Forum) {
                $items[] = new BreadcrumbItem(
                    $part->title(),
                    '/forums/' . $part->slug()->value(),
                );
            } else {
                $items[] = new BreadcrumbItem($part->title());
            }
        }
        $items[] = new BreadcrumbItem($thread->title()->value());

        return new BreadcrumbTrail($items);
    }

    private function threadId(Request $request): ?EntityId
    {
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($params) ? ($params['threadId'] ?? null) : null;
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1
            ? EntityId::fromString($value)
            : null;
    }

    private function page(Request $request): int
    {
        $value = $request->query()['page'] ?? 1;
        if (is_int($value)) {
            return max(1, min(1_000_000, $value));
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,6}$/D', $value) !== 1) {
            return 1;
        }

        return max(1, min(1_000_000, (int) $value));
    }

    private static function initial(string $username): string
    {
        if ($username === '') {
            return '?';
        }
        if (preg_match('/^./us', $username, $match) === 1) {
            return strtoupper($match[0]);
        }

        return '?';
    }

    private static function date(string $value): string
    {
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? $value : date('d.m.Y H:i', $timestamp);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
