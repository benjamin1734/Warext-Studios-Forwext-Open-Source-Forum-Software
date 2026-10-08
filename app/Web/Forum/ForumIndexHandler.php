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
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;

final readonly class ForumIndexHandler implements RequestHandlerInterface
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
        $actor = $this->viewers->resolve($request);
        $allNodes = $this->nodes->all();
        $hierarchy = new ForumNodeHierarchy($allNodes);

        $forums = array_values(array_filter(
            $allNodes,
            fn (ForumNode $node): bool => $node->type() === ForumNodeType::Forum
                && $hierarchy->isDiscoverable($node->id())
                && $this->canViewIndexForum($actor, $node),
        ));

        $forumIds = array_map(static fn (ForumNode $node): EntityId => $node->id(), $forums);
        $summaries = $this->reader->forumSummaries($forumIds);
        $recent = $this->reader->recentThreads($forumIds, 8);

        $groups = $this->groups($hierarchy, $forums);
        $body = '<div class="forum-home-layout"><div class="forum-home-main">'
            . '<section class="forum-hero forum-page-head"><div>'
            . '<h1>Forumlar</h1><p>Topluluk kategorileri, forumlar ve son hareketler.</p></div>'
            . '<div class="forum-hero-actions">'
            . ($actor === null ? '' : '<a class="fx-btn fx-btn--primary" href="' . self::e($this->basePath->prepend('/activity/threads/new')) . '">Yeni konular</a>')
            . '<a class="fx-btn" href="' . self::e($this->basePath->prepend('/search')) . '">Forumlarda ara</a>'
            . '</div></section>';

        if ($groups === []) {
            $body .= '<section class="card forum-empty-state"><div class="forum-node-icon">◇</div>'
                . '<h2>Henüz görüntülenebilir forum yok</h2>'
                . '<p class="muted">Yönetim panelinden kategori ve forumlar oluşturulduğunda burada görünecek.</p></section>';
        } else {
            foreach ($groups as $group) {
                $body .= $this->renderGroup($group['title'], $group['description'], $group['forums'], $hierarchy, $summaries);
            }
        }

        $body .= '</div><aside class="forum-home-side" aria-label="Forum özeti">'
            . $this->renderRecent($recent)
            . ($actor === null
                ? '<section class="card forum-side-card forum-side-card--discovery"><h2>Keşfet</h2>'
                    . '<a href="' . self::e($this->basePath->prepend('/activity')) . '">Son hareketler <span>→</span></a>'
                    . '<a href="' . self::e($this->basePath->prepend('/search')) . '">Forumlarda ara <span>→</span></a>'
                    . '<a href="' . self::e($this->basePath->prepend('/members')) . '">Üyeleri keşfet <span>→</span></a>'
                    . '</section>'
                : '<section class="card forum-side-card forum-side-card--discovery"><h2>Keşfet</h2>'
                . '<a href="' . self::e($this->basePath->prepend('/activity/threads/new')) . '">Yeni konular <span>→</span></a>'
                . '<a href="' . self::e($this->basePath->prepend('/activity/threads/unread')) . '">Okunmamış <span>→</span></a>'
                . '<a href="' . self::e($this->basePath->prepend('/activity/threads/trending')) . '">Gündem <span>→</span></a>'
                . '<a href="' . self::e($this->basePath->prepend('/activity/threads/featured')) . '">Öne çıkanlar <span>→</span></a>'
                . '<a href="' . self::e($this->basePath->prepend('/activity/profile-posts')) . '">Profil gönderileri <span>→</span></a>'
                . '</section>')
            . '<section class="card forum-side-card"><h2>Topluluk</h2>'
            . '<a href="' . self::e($this->basePath->prepend('/members/online')) . '">Çevrimiçi üyeler <span>→</span></a>'
            . ($actor === null ? '' : '<a href="' . self::e($this->basePath->prepend('/stats')) . '">Forum istatistikleri <span>→</span></a>')
            . '<a href="' . self::e($this->basePath->prepend('/faq')) . '">SSS <span>→</span></a>'
            . '</section>'
            . $this->renderStats($summaries)
            . '</aside></div>';

        $path = parse_url($request->uri(), PHP_URL_PATH);
        $routePath = is_string($path) && $path !== '' ? $this->basePath->strip($path) : null;
        $home = $routePath === '/';

        return Response::html(ProfileHtml::page(
            $home ? 'Ana Sayfa' : 'Forumlar',
            $body,
            $this->basePath,
            breadcrumbs: $home ? null : BreadcrumbTrail::page('Forumlar'),
            authenticated: $actor !== null,
            viewerId: $actor?->value(),
        ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=30' : 'private, no-store');
    }

    private function canViewIndexForum(?EntityId $actor, ForumNode $node): bool
    {
        if ($actor === null) {
            return true;
        }

        return $this->authorizer->allows(
            $actor,
            PermissionKey::fromString(self::VIEW_PERMISSION),
            $node->id(),
        );
    }

    /**
     * @param list<ForumNode> $forums
     * @return list<array{title:string,description:string,forums:list<ForumNode>}>
     */
    private function groups(ForumNodeHierarchy $hierarchy, array $forums): array
    {
        $groups = [];
        foreach ($forums as $forum) {
            $trail = $hierarchy->breadcrumb($forum->id());
            $root = $trail[0] ?? $forum;
            if ($root->type() === ForumNodeType::Category) {
                $key = $root->id()->value();
                $title = $root->title();
                $description = $root->description();
            } else {
                $key = '__root_forums';
                $title = 'Forumlar';
                $description = 'Ana forum alanları';
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'title' => $title,
                    'description' => $description,
                    'forums' => [],
                ];
            }
            $groups[$key]['forums'][] = $forum;
        }

        return array_values($groups);
    }

    /**
     * @param list<ForumNode> $forums
     * @param array<string,array{
     *   thread_count:int,post_count:int,latest_thread_id:?string,latest_thread_title:?string,
     *   latest_username:?string,latest_at:?string
     * }> $summaries
     */
    private function renderGroup(
        string $title,
        string $description,
        array $forums,
        ForumNodeHierarchy $hierarchy,
        array $summaries,
    ): string {
        $html = '<section class="forum-category"><header class="forum-category-head"><div><h2>'
            . self::e($title) . '</h2>';
        if ($description !== '') {
            $html .= '<p>' . self::e($description) . '</p>';
        }
        $html .= '</div><span>' . number_format(count($forums), 0, ',', '.') . ' forum</span>'
            . '</header><div class="forum-node-list">';

        foreach ($forums as $forum) {
            $summary = $summaries[$forum->id()->value()] ?? [
                'thread_count' => 0,
                'post_count' => 0,
                'latest_thread_id' => null,
                'latest_thread_title' => null,
                'latest_username' => null,
                'latest_at' => null,
            ];
            $forumUrl = $this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value()));
            $trail = $hierarchy->breadcrumb($forum->id());
            $parents = array_slice($trail, 1, -1);
            $path = $parents === []
                ? ''
                : implode(' › ', array_map(static fn (ForumNode $node): string => $node->title(), $parents));

            $html .= '<article class="forum-node"><a class="forum-node-icon" href="' . self::e($forumUrl)
                . '" aria-label="' . self::e($forum->title()) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v12H8.6L4 20V4Zm2 2v9.8l1.9-1.8H18V6H6Z"/></svg></a>'
                . '<div class="forum-node-main"><h3><a href="' . self::e($forumUrl) . '">'
                . self::e($forum->title()) . '</a></h3>';
            if ($forum->description() !== '') {
                $html .= '<p>' . self::e($forum->description()) . '</p>';
            }
            if ($path !== '') {
                $html .= '<div class="forum-node-path">' . self::e($path) . '</div>';
            }
            $html .= '</div><div class="forum-node-counts"><div><strong>'
                . number_format($summary['thread_count'], 0, ',', '.') . '</strong><span>Konu</span></div><div><strong>'
                . number_format($summary['post_count'], 0, ',', '.') . '</strong><span>Mesaj</span></div></div>'
                . '<div class="forum-node-last">' . $this->renderLatest($summary) . '</div></article>';
        }

        return $html . '</div></section>';
    }

    /**
     * @param array{
     *   latest_thread_id:?string,latest_thread_title:?string,latest_username:?string,latest_at:?string,
     *   thread_count:int,post_count:int
     * } $summary
     */
    private function renderLatest(array $summary): string
    {
        if ($summary['latest_thread_id'] === null || $summary['latest_thread_title'] === null) {
            return '<span class="muted">Henüz konu yok</span>';
        }

        $url = $this->basePath->prepend('/threads/' . rawurlencode($summary['latest_thread_id']));
        $username = $summary['latest_username'];
        $avatar = $username === null
            ? '<span class="forum-last-avatar" aria-hidden="true">?</span>'
            : '<a class="forum-last-avatar" href="' . self::e(ProfileHtml::memberPath($this->basePath, $username))
                . '" aria-label="' . self::e($username) . ' profili">' . ProfileHtml::initial($username) . '</a>';
        $user = $username === null
            ? ''
            : '<a class="forum-last-user" href="' . self::e(ProfileHtml::memberPath($this->basePath, $username))
                . '">' . self::e($username) . '</a>';
        $time = $summary['latest_at'] === null ? '' : self::e(self::date($summary['latest_at']));
        $metaHtml = $user === '' && $time === ''
            ? ''
            : '<span class="forum-last-meta">' . $user
                . ($user !== '' && $time !== '' ? '<span aria-hidden="true"> · </span>' : '')
                . $time . '</span>';

        return '<div class="forum-last-activity">' . $avatar . '<div class="forum-last-content">'
            . '<a class="forum-last-title" href="' . self::e($url) . '">'
            . self::e($summary['latest_thread_title']) . '</a>' . $metaHtml . '</div></div>';
    }

    /**
     * @param array<string,array{thread_count:int,post_count:int,latest_thread_id:?string,latest_thread_title:?string,latest_username:?string,latest_at:?string}> $summaries
     */
    private function renderStats(array $summaries): string
    {
        $threads = 0;
        $posts = 0;
        foreach ($summaries as $summary) {
            $threads += $summary['thread_count'];
            $posts += $summary['post_count'];
        }

        return '<section class="card forum-side-card forum-side-card--stats"><h2>Forum istatistikleri</h2>'
            . '<div class="forum-mini-stats"><div><strong>' . number_format(count($summaries), 0, ',', '.')
            . '</strong><span>Forum</span></div><div><strong>' . number_format($threads, 0, ',', '.')
            . '</strong><span>Konu</span></div><div><strong>' . number_format($posts, 0, ',', '.')
            . '</strong><span>Mesaj</span></div></div></section>';
    }

    /**
     * @param list<array{thread_id:string,forum_node_id:string,title:string,author_username:?string,activity_at:string}> $recent
     */
    private function renderRecent(array $recent): string
    {
        $html = '<section class="card forum-side-card"><h2>Gündem</h2>';
        if ($recent === []) {
            return '<section class="card forum-side-card forum-side-card--empty"><h2>Gündem</h2>'
                . '<p class="forum-side-empty">Henüz görünür konu bulunmuyor.</p></section>';
        }

        $html .= '<div class="forum-recent-list">';
        foreach ($recent as $thread) {
            $url = $this->basePath->prepend('/threads/' . rawurlencode($thread['thread_id']));
            $username = $thread['author_username'];
            $avatar = $username === null
                ? '<span class="forum-recent-avatar" aria-hidden="true">?</span>'
                : '<a class="forum-recent-avatar" href="' . self::e(ProfileHtml::memberPath($this->basePath, $username))
                    . '" aria-label="' . self::e($username) . ' profili">' . ProfileHtml::initial($username) . '</a>';
            $author = $username === null
                ? ''
                : '<a class="forum-recent-user" href="' . self::e(ProfileHtml::memberPath($this->basePath, $username))
                    . '">' . self::e($username) . '</a>';
            $time = '<time datetime="' . self::e($thread['activity_at']) . '">'
                . self::e(self::date($thread['activity_at'])) . '</time>';

            $html .= '<div class="forum-recent-item">' . $avatar . '<div class="forum-recent-content">'
                . '<a class="forum-recent-title" href="' . self::e($url) . '">' . self::e($thread['title']) . '</a>'
                . '<span class="forum-recent-meta">' . $author
                . ($author === '' ? '' : '<span aria-hidden="true"> · </span>') . $time
                . '</span></div></div>';
        }

        return $html . '</div></section>';
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
