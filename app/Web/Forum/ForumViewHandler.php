<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Discovery\DatabaseForumPublicReader;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class ForumViewHandler implements RequestHandlerInterface
{
    private const VIEW_PERMISSION = 'forum.view';

    public function __construct(
        private ForumNodeRepository $nodes,
        private DatabaseForumPublicReader $reader,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $slug = $this->slug($request);
        if ($slug === null) {
            return Response::text('Not Found', 404);
        }

        $node = $this->nodes->findBySlug($slug);
        if ($node === null || $node->type() !== ForumNodeType::Forum) {
            return Response::text('Not Found', 404);
        }

        $actor = $this->viewers->resolve($request);
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        if (!$this->canView($actor, $hierarchy, $node)) {
            return Response::text($actor === null ? 'Not Found' : 'Forbidden', $actor === null ? 404 : 403)
                ->withHeader('Cache-Control', 'no-store');
        }

        $page = $this->page($request);
        $perPage = $node->forumSettings()?->threadsPerPage() ?? 30;
        $perPage = max(1, min(100, $perPage));
        $threads = $this->reader->threads($node->id(), $page, $perPage);
        if ($page > $threads['pages'] && $threads['total'] > 0) {
            return Response::text('Not Found', 404);
        }

        $body = '<section class="forum-view-head"><div><span class="forum-eyebrow">FORUM</span><h1>'
            . self::e($node->title()) . '</h1>';
        if ($node->description() !== '') {
            $body .= '<p>' . self::e($node->description()) . '</p>';
        }
        $body .= '</div><div class="forum-view-actions">';
        if ($this->canCreateThread($actor, $node)) {
            $body .= '<a class="fx-btn fx-btn--primary" href="'
                . self::e($this->basePath->prepend('/forums/' . rawurlencode($node->slug()->value()) . '/new-thread'))
                . '">Yeni konu</a>';
        }
        $body .= '<a class="fx-btn" href="' . self::e($this->basePath->prepend('/search')) . '">Bu toplulukta ara</a>'
            . '</div></section>';

        $children = array_values(array_filter(
            $hierarchy->navigationChildren($node->id()),
            fn (ForumNode $child): bool => $child->type() === ForumNodeType::Forum
                && $this->canView($actor, $hierarchy, $child),
        ));
        if ($children !== []) {
            $body .= '<section class="card forum-subforums"><h2>Alt forumlar</h2><div>';
            foreach ($children as $child) {
                $body .= '<a href="' . self::e($this->basePath->prepend('/forums/' . rawurlencode($child->slug()->value())))
                    . '"><strong>' . self::e($child->title()) . '</strong>'
                    . ($child->description() === '' ? '' : '<span>' . self::e($child->description()) . '</span>')
                    . '</a>';
            }
            $body .= '</div></section>';
        }

        $body .= '<section class="forum-thread-panel"><header class="forum-thread-panel-head"><div><h2>Konular</h2>'
            . '<p>' . number_format($threads['total'], 0, ',', '.') . ' görünür konu</p></div></header>';

        if ($threads['rows'] === []) {
            $body .= '<div class="card forum-empty-state"><div class="forum-node-icon">◇</div>'
                . '<h3>Henüz konu yok</h3><p class="muted">Bu forumdaki ilk konu oluşturulduğunda burada listelenecek.</p></div>';
        } else {
            $body .= '<div class="forum-thread-list">';
            foreach ($threads['rows'] as $thread) {
                $body .= $this->renderThread($thread);
            }
            $body .= '</div>' . $this->pagination($node, $threads['page'], $threads['pages']);
        }
        $body .= '</section>';

        return Response::html(ProfileHtml::page(
            $node->title(),
            $body,
            $this->basePath,
            breadcrumbs: $this->breadcrumbs($hierarchy, $node),
            authenticated: $actor !== null,
            viewerId: $actor?->value(),
        ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=30' : 'private, no-store');
    }

    private function canCreateThread(?EntityId $actor, ForumNode $node): bool
    {
        $settings = $node->forumSettings();
        return $actor !== null
            && $settings !== null
            && $settings->allowNewThreads()
            && $this->authorizer->allows($actor, ThreadPermission::Create->key(), $node->id());
    }

    private function canView(?EntityId $actor, ForumNodeHierarchy $hierarchy, ForumNode $node): bool
    {
        if (!$hierarchy->isResolvable($node->id())) {
            return false;
        }
        if ($actor === null) {
            return $hierarchy->isDiscoverable($node->id());
        }

        return $this->authorizer->allows(
            $actor,
            PermissionKey::fromString(self::VIEW_PERMISSION),
            $node->id(),
        );
    }

    /** @param array{thread_id:string,title:string,sticky:bool,featured:bool,locked:bool,created_at:string,author_username:?string,post_count:int,last_post_at:?string,last_post_username:?string} $thread */
    private function renderThread(array $thread): string
    {
        $url = $this->basePath->prepend('/threads/' . rawurlencode($thread['thread_id']));
        $badges = '';
        if ($thread['sticky']) {
            $badges .= '<span class="thread-badge">Sabit</span>';
        }
        if ($thread['featured']) {
            $badges .= '<span class="thread-badge thread-badge--accent">Öne çıkan</span>';
        }
        if ($thread['locked']) {
            $badges .= '<span class="thread-badge">Kilitli</span>';
        }

        $author = $thread['author_username'] ?? 'Silinmiş üye';
        $lastUser = $thread['last_post_username'] ?? $author;
        $lastAt = $thread['last_post_at'] ?? $thread['created_at'];
        $replyCount = max(0, $thread['post_count'] - 1);

        return '<article class="forum-thread-row"><div class="thread-status-icon" aria-hidden="true">●</div>'
            . '<div class="forum-thread-main">' . ($badges === '' ? '' : '<div class="thread-badges">' . $badges . '</div>')
            . '<h3><a href="' . self::e($url) . '">' . self::e($thread['title']) . '</a></h3>'
            . '<p>' . self::e($author) . ' · ' . self::e(self::date($thread['created_at'])) . '</p></div>'
            . '<div class="forum-thread-count"><strong>' . number_format($replyCount, 0, ',', '.')
            . '</strong><span>Yanıt</span></div>'
            . '<div class="forum-thread-last"><strong>' . self::e($lastUser) . '</strong><span>'
            . self::e(self::date($lastAt)) . '</span></div></article>';
    }

    private function pagination(ForumNode $node, int $page, int $pages): string
    {
        if ($pages <= 1) {
            return '';
        }

        $html = '<nav class="pagination" aria-label="Konu sayfaları">';
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        for ($current = $start; $current <= $end; $current++) {
            $url = $this->basePath->prepend('/forums/' . rawurlencode($node->slug()->value()))
                . ($current === 1 ? '' : '?page=' . $current);
            $html .= '<a href="' . self::e($url) . '"'
                . ($current === $page ? ' aria-current="page"' : '') . '>'
                . $current . '</a>';
        }

        return $html . '</nav>';
    }

    private function breadcrumbs(ForumNodeHierarchy $hierarchy, ForumNode $node): BreadcrumbTrail
    {
        $items = [
            new BreadcrumbItem('Ana Sayfa', '/'),
            new BreadcrumbItem('Forumlar', '/forums'),
        ];
        foreach ($hierarchy->breadcrumb($node->id()) as $part) {
            if ($part->id()->equals($node->id())) {
                $items[] = new BreadcrumbItem($part->title());
                continue;
            }
            if ($part->type() === ForumNodeType::Forum) {
                $items[] = new BreadcrumbItem(
                    $part->title(),
                    '/forums/' . $part->slug()->value(),
                );
            } else {
                $items[] = new BreadcrumbItem($part->title());
            }
        }

        return new BreadcrumbTrail($items);
    }

    private function slug(Request $request): ?ForumNodeSlug
    {
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($params) ? ($params['slug'] ?? null) : null;
        if (!is_string($value)) {
            return null;
        }
        try {
            return ForumNodeSlug::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
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
