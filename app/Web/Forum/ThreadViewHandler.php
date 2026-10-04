<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\App\Web\Editor\RichEditorView;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentPermission;
use Forwext\Core\Forum\Attachment\AttachmentRecord;
use Forwext\Core\Forum\Attachment\DatabaseAttachmentRepository;
use Forwext\Core\Forum\Discovery\DatabaseForumPublicReader;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorSurface;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\State\DatabaseDiscussionStateRepository;
use Forwext\Core\Forum\State\DiscussionStateException;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
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
        private DatabaseDiscussionStateRepository $discussionState,
        private DatabaseAttachmentRepository $attachments,
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
        $csrfToken = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
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

        if ($actor !== null && $posts['rows'] !== []) {
            $lastVisiblePosition = max(array_column($posts['rows'], 'position'));
            try {
                $this->discussionState->markThreadRead(
                    $actor,
                    $threadId,
                    $lastVisiblePosition,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
            } catch (DiscussionStateException|InvalidArgumentException) {
                // A concurrent moderation/read-state change must not make an otherwise viewable thread fail.
            }
        }

        $attachmentsByPost = [];
        if (
            $actor !== null
            && $posts['rows'] !== []
            && $this->authorizer->allows($actor, AttachmentPermission::Download->key(), $forum->id())
        ) {
            $postIds = array_map(
                static fn (array $post): EntityId => EntityId::fromString($post['post_id']),
                $posts['rows'],
            );
            foreach ($this->attachments->attachedForPosts($postIds) as $attachment) {
                $postId = $attachment->postId?->value();
                if ($postId !== null) {
                    $attachmentsByPost[$postId][] = $attachment;
                }
            }
        }

        $body = '<section class="thread-view-head forum-page-head"><div class="thread-view-title"><div class="thread-badges">'
            . ($thread->isSticky() ? '<span class="thread-badge">Sabit</span>' : '')
            . ($thread->isFeatured() ? '<span class="thread-badge thread-badge--accent">Öne çıkan</span>' : '')
            . ($thread->isLocked() ? '<span class="thread-badge">Kilitli</span>' : '')
            . '</div><h1>' . self::e($thread->title()->value()) . '</h1><p>'
            . number_format(max(0, $posts['total'] - 1), 0, ',', '.') . ' yanıt · '
            . number_format($posts['total'], 0, ',', '.') . ' mesaj</p></div>'
            . '<div class="thread-view-actions"><div class="thread-view-action-main">';
        $canReply = $this->canReply($actor, $thread, $forum);
        if ($canReply) {
            $body .= '<a class="fx-btn fx-btn--primary" href="#quick-reply">Yanıtla</a>';
        }
        $body .= '<a class="fx-btn" href="' . self::e($this->basePath->prepend('/forums/' . rawurlencode($forum->slug()->value())))
            . '">Foruma dön</a></div>';
        if ($actor !== null && is_string($csrfToken) && $csrfToken !== '') {
            $body .= '<div class="thread-view-action-follow">' . DiscussionWatchHtml::form(
                $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()) . '/watch'),
                $csrfToken,
                $this->discussionState->threadWatch($actor, $thread->id()),
            ) . '</div>';
        }
        $body .= '</div></section>';

        if (($request->query()['watch'] ?? null) === 'updated') {
            $body .= '<div class="forum-notice">Konu takip tercihin güncellendi.</div>';
        }

        if (($request->query()['reply_pending'] ?? null) === '1') {
            $body .= '<div class="forum-notice">Yanıtınız gönderildi ve moderasyon onayı bekliyor.</div>';
        }
        if (($request->query()['attachment_warning'] ?? null) === '1') {
            $body .= '<div class="forum-notice forum-notice--warning">Mesajınız gönderildi ancak bir veya daha fazla dosya mesaja bağlanamadı. Dosyayı yeniden ekleyebilirsiniz.</div>';
        }

        $body .= $this->replyStateNotice($actor, $thread, $forum, $canReply);

        if ($posts['rows'] === []) {
            $body .= '<section class="card forum-empty-state"><h2>Görüntülenebilir mesaj yok</h2></section>';
        } else {
            $pagination = $this->pagination($thread, $posts['page'], $posts['pages']);
            if ($pagination !== '') {
                $body .= '<div class="thread-pagination thread-pagination--top">' . $pagination . '</div>';
            }
            $body .= '<div class="thread-post-list">';
            foreach ($posts['rows'] as $post) {
                $body .= $this->renderPost(
                    $post,
                    $thread,
                    $actor,
                    $canReply,
                    $attachmentsByPost[$post['post_id']] ?? [],
                );
            }
            $body .= '</div>';
            if ($pagination !== '') {
                $body .= '<div class="thread-pagination thread-pagination--bottom">' . $pagination . '</div>';
            }
        }

        if ($canReply && is_string($csrfToken) && $csrfToken !== '') {
            $body .= $this->quickReply($thread, $forum, $csrfToken);
        }

        return Response::html(ProfileHtml::page(
            $thread->title()->value(),
            $body,
            $this->basePath,
            breadcrumbs: $this->breadcrumbs($hierarchy, $forum, $thread),
            authenticated: $actor !== null,
            viewerId: $actor?->value(),
            headAssets: $canReply ? RichEditorView::assets($this->basePath) : '',
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

    private function replyStateNotice(
        ?EntityId $actor,
        Thread $thread,
        ForumNode $forum,
        bool $canReply,
    ): string {
        if ($canReply) {
            return '';
        }
        if ($thread->isLocked()) {
            return '<div class="thread-state-strip thread-state-strip--locked"><strong>Konu kilitli.</strong>'
                . '<span>Yeni yanıt kabul edilmiyor; mevcut mesajları okumaya devam edebilirsin.</span></div>';
        }
        $settings = $forum->forumSettings();
        if ($settings === null || !$settings->allowReplies()) {
            return '<div class="thread-state-strip"><strong>Yanıtlar kapalı.</strong>'
                . '<span>Bu forum ayarları yeni yanıt gönderimine izin vermiyor.</span></div>';
        }
        if ($actor !== null) {
            return '<div class="thread-state-strip"><strong>Yanıt yetkisi yok.</strong>'
                . '<span>Bu forumda yeni mesaj gönderme iznin bulunmuyor.</span></div>';
        }

        return '';
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

    private function quickReply(Thread $thread, ForumNode $forum, string $token): string
    {
        $threadUrl = $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()));
        $action = $threadUrl . '/reply';

        return '<section class="thread-quick-reply card" id="quick-reply">'
            . '<header class="thread-quick-reply-head"><div>'
            . '<h2>Yanıt yaz</h2><p>Konu sayfasından ayrılmadan mesajını gönderebilirsin.</p></div>'
            . '<a class="fx-btn" href="' . self::e($action) . '">Tam editörü aç</a></header>'
            . '<div class="thread-quick-reply-capabilities" aria-label="Düzenleyici özellikleri">'
            . '<span>Önizleme</span><span>Alıntı</span><span>Yazım denetimi</span><span>Dosya ekleme</span></div>'
            . '<form class="thread-quick-reply-form" method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($token) . '">'
            . RichEditorView::render(
                'body',
                '',
                EditorSurface::Post,
                new EditorLimits(),
                $this->basePath,
                'thread-quick-reply-editor',
                null,
                $forum->id(),
            )
            . '<div class="thread-quick-reply-actions"><a class="fx-btn" href="' . self::e($threadUrl)
            . '">Vazgeç</a><button class="fx-btn fx-btn--primary" type="submit">Yanıtı gönder</button></div>'
            . '</form></section>';
    }

    /** @param array{post_id:string,position:int,body_source:string,created_at:string,updated_at:string,author_user_id:?string,author_username:?string} $post */
    private function renderPost(
        array $post,
        Thread $thread,
        ?EntityId $actor,
        bool $canReply,
        array $attachments,
    ): string {
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
            ? '<strong class="thread-post-author-name">' . self::e($username) . '</strong>'
            : '<a class="thread-post-author-name" href="' . self::e($profileUrl) . '">' . self::e($username) . '</a>';
        $avatar = $profileUrl === null
            ? '<span class="thread-post-avatar" aria-hidden="true">' . self::e($initial) . '</span>'
            : '<a class="thread-post-avatar" href="' . self::e($profileUrl) . '" aria-label="'
                . self::e($username) . ' profili">' . self::e($initial) . '</a>';
        $edited = $post['updated_at'] !== $post['created_at']
            ? '<span class="thread-post-edited">Düzenlendi · ' . self::e(self::date($post['updated_at'])) . '</span>'
            : '';
        $threadAuthorId = $thread->authorUserId();
        $isThreadStarter = $threadAuthorId !== null
            && $post['author_user_id'] !== null
            && hash_equals($threadAuthorId->value(), $post['author_user_id']);
        $authorBadges = '<div class="thread-post-author-badges"><span class="thread-author-badge">'
            . ($profileUrl === null ? 'Silinmiş hesap' : 'Topluluk üyesi') . '</span>'
            . ($isThreadStarter ? '<span class="thread-author-badge thread-author-badge--starter">Konu sahibi</span>' : '')
            . '</div>';
        $authorLinks = '';
        if ($profileUrl !== null) {
            $authorLinks = '<nav class="thread-post-author-links" aria-label="' . self::e($username) . ' bağlantıları">'
                . '<a href="' . self::e($profileUrl) . '">Profil</a>';
            if ($actor !== null) {
                $authorLinks .= '<a href="' . self::e($profileUrl . '/content/threads') . '">Konular</a>'
                    . '<a href="' . self::e($profileUrl . '/content/posts') . '">Mesajlar</a>';
            }
            $authorLinks .= '</nav>';
        }

        return '<article class="thread-post' . ($isThreadStarter ? ' is-thread-starter' : '') . '" id="post-'
            . self::e($post['post_id']) . '">'
            . '<aside class="thread-post-author">' . $avatar
            . '<div class="thread-post-author-copy">' . $author . $authorBadges . $authorLinks . '</div></aside>'
            . '<div class="thread-post-body"><header class="thread-post-meta">'
            . '<time datetime="' . self::e($post['created_at']) . '">' . self::e(self::date($post['created_at'])) . '</time>'
            . '<a class="thread-post-permalink" href="#post-' . self::e($post['post_id']) . '" aria-label="Mesaj '
            . number_format($post['position'], 0, ',', '.') . ' bağlantısı">#'
            . number_format($post['position'], 0, ',', '.') . '</a></header>'
            . '<div class="thread-post-content">' . $content . '</div>'
            . $this->renderAttachments($attachments)
            . '<footer class="thread-post-footer"><div class="thread-post-footer-meta">' . $edited . '</div>'
            . $this->interactionControls($post, $actor, $canReply) . '</footer>'
            . '</div></article>';
    }

    /** @param list<AttachmentRecord> $attachments */
    private function renderAttachments(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $items = '';
        foreach ($attachments as $attachment) {
            $url = $this->basePath->prepend('/attachments/' . rawurlencode($attachment->attachmentId->value()));
            $filename = $attachment->filename->value();
            $preview = '';
            if ($attachment->thumbnailPath !== null) {
                $preview = '<a class="thread-attachment-thumb" href="' . self::e($url) . '" aria-label="'
                    . self::e($filename) . ' dosyasını aç"><img loading="lazy" src="' . self::e($url . '?thumbnail=1')
                    . '" alt=""></a>';
            }
            $items .= '<article class="thread-attachment">' . $preview
                . '<div class="thread-attachment-copy"><strong>' . self::e($filename) . '</strong>'
                . '<span>' . self::e(self::fileSize($attachment->sizeBytes)) . ' · ' . self::e($attachment->mediaType)
                . '</span></div><a class="fx-btn" href="' . self::e($url) . '">İndir</a></article>';
        }

        return '<section class="thread-attachments" aria-label="Mesaj dosyaları"><h3>Ek dosyalar</h3>'
            . '<div class="thread-attachment-list">' . $items . '</div></section>';
    }

    /** @param array{post_id:string,position:int,body_source:string,created_at:string,updated_at:string,author_user_id:?string,author_username:?string} $post */
    private function interactionControls(array $post, ?EntityId $actor, bool $canReply): string
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
            . ($canReply ? '<button type="button" class="fx-btn thread-quote-button" data-quote-post>Alıntıla</button>' : '')
            . '<details class="thread-reaction-menu" data-reaction-menu><summary class="fx-btn" aria-label="Tepki seçenekleri">'
            . 'Tepki <span class="thread-reaction-total" data-reaction-total></span></summary>'
            . '<div class="thread-reaction-popover"><div class="thread-reaction-counts" data-reaction-counts></div>'
            . '<div class="thread-reaction-options">' . $reactions . '</div></div></details>'
            . '<details class="thread-bookmark-menu"><summary class="fx-btn" aria-label="Mesajı kaydet">Kaydet</summary>'
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

        $base = $this->basePath->prepend('/threads/' . rawurlencode($thread->id()->value()));
        $url = static fn (int $target): string => $target === 1 ? $base : $base . '?page=' . $target;
        $html = '<nav class="pagination thread-pagination-nav" aria-label="Konu mesaj sayfaları">';

        if ($page > 1) {
            $html .= '<a class="pagination-edge" href="' . self::e($url($page - 1)) . '" rel="prev">← Önceki</a>';
        }

        $targets = [1 => true, $pages => true];
        for ($candidate = max(1, $page - 2); $candidate <= min($pages, $page + 2); $candidate++) {
            $targets[$candidate] = true;
        }
        $targets = array_keys($targets);
        sort($targets, SORT_NUMERIC);

        $previous = null;
        foreach ($targets as $target) {
            if ($previous !== null && $target > $previous + 1) {
                $html .= '<span class="pagination-gap" aria-hidden="true">…</span>';
            }
            $html .= '<a href="' . self::e($url($target)) . '"'
                . ($target === $page ? ' aria-current="page"' : '') . '>'
                . number_format($target, 0, ',', '.') . '</a>';
            $previous = $target;
        }

        if ($page < $pages) {
            $html .= '<a class="pagination-edge" href="' . self::e($url($page + 1)) . '" rel="next">Sonraki →</a>';
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

    private static function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
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
