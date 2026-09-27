<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileComment;
use Forwext\Core\Profile\Activity\ProfilePost;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ProfileActivityWallRenderer
{
    public function __construct(
        private ProfileActivityService $activity,
        private UserRepository $users,
        private BasePath $basePath,
        private DateTimeZone $timezone,
    ) {
    }

    public function render(EntityId $viewerId, EntityId $profileOwnerId): string
    {
        try {
            if (!$this->activity->canViewProfile($viewerId, $profileOwnerId)) {
                return '';
            }
            $posts = $this->activity->posts($viewerId, $profileOwnerId, 8, 0);
        } catch (PermissionDeniedException|ProfileActivityException|InvalidArgumentException) {
            return '';
        }

        $authorCache = [];
        $canPost = $this->canPost($viewerId, $profileOwnerId);
        $composer = $canPost
            ? '<form class="profile-wall-composer card" data-profile-wall-post-form>'
                . '<label><span>Profil gönderisi</span><textarea name="body" maxlength="10000" rows="3" '
                . 'placeholder="Bu profile bir şey yaz…"></textarea></label>'
                . '<div><span class="profile-wall-status" data-profile-wall-status role="status" aria-live="polite"></span>'
                . '<button class="fx-btn fx-btn--primary" type="submit">Paylaş</button></div></form>'
            : '';

        $cards = '';
        foreach ($posts as $post) {
            $cards .= $this->post($viewerId, $profileOwnerId, $post, $authorCache);
        }
        if ($cards === '') {
            $cards = '<div class="profile-wall-empty card"><strong>Henüz profil gönderisi yok.</strong>'
                . '<span>Bu profilde paylaşım yapıldığında burada görünecek.</span></div>';
        }

        return '<section class="profile-wall section" id="activity" data-profile-activity-wall data-profile-owner-id="'
            . ProfileHtml::escape($profileOwnerId->value()) . '"><div class="profile-wall-head"><div><h2>Profil Akışı</h2>'
            . '<p class="muted">Profil gönderileri, yorumlar ve tepkiler.</p></div></div>'
            . $composer . '<div class="profile-wall-list">' . $cards . '</div></section>';
    }

    /**
     * @param array<string,User|null> $authorCache
     */
    private function post(
        EntityId $viewerId,
        EntityId $profileOwnerId,
        ProfilePost $post,
        array &$authorCache,
    ): string {
        $author = $this->author($post->authorUserId, $authorCache);
        $comments = [];
        try {
            $comments = $this->activity->comments($viewerId, $post->id, 3, 0);
        } catch (PermissionDeniedException|ProfileActivityException|InvalidArgumentException) {
            $comments = [];
        }

        $commentHtml = '';
        foreach ($comments as $comment) {
            $commentHtml .= $this->comment($comment, $authorCache);
        }
        if ($commentHtml === '') {
            $commentHtml = '<div class="profile-wall-no-comments muted">Henüz yorum yok.</div>';
        }

        $ownPost = $post->authorUserId?->value() === $viewerId->value();
        $reactionControls = $ownPost
            ? '<span class="muted">Kendi profil gönderine tepki veremezsin.</span>'
            : $this->reactionControls();

        return '<article class="profile-wall-post card" data-profile-wall-post data-profile-post-id="'
            . ProfileHtml::escape($post->id->value()) . '"><header><div><strong>'
            . ProfileHtml::escape($author) . '</strong><time datetime="'
            . ProfileHtml::escape($post->createdAt->format(DATE_ATOM)) . '">'
            . ProfileHtml::escape($post->createdAt->setTimezone($this->timezone)->format('d.m.Y H:i'))
            . '</time></div></header><p class="profile-wall-body">'
            . nl2br(ProfileHtml::escape($post->body->source()), false) . '</p>'
            . '<div class="profile-wall-interactions"><details data-profile-wall-reaction-menu>'
            . '<summary class="fx-btn">Tepkiler <span data-profile-wall-reaction-total></span></summary>'
            . '<div class="profile-wall-reaction-popover"><div data-profile-wall-reaction-counts></div>'
            . '<div class="profile-wall-reaction-options">' . $reactionControls . '</div></div></details></div>'
            . '<div class="profile-wall-comments">' . $commentHtml . '</div>'
            . '<form class="profile-wall-comment-form" data-profile-wall-comment-form>'
            . '<label><span>Yorum yaz</span><textarea name="body" maxlength="10000" rows="2" placeholder="Yorumun…"></textarea></label>'
            . '<div><span class="profile-wall-status" data-profile-wall-status role="status" aria-live="polite"></span>'
            . '<button class="fx-btn" type="submit">Yorum yap</button></div></form></article>';
    }

    /**
     * @param array<string,User|null> $authorCache
     */
    private function comment(ProfileComment $comment, array &$authorCache): string
    {
        $author = $this->author($comment->authorUserId, $authorCache);

        return '<article class="profile-wall-comment"><div><strong>' . ProfileHtml::escape($author)
            . '</strong><time datetime="' . ProfileHtml::escape($comment->createdAt->format(DATE_ATOM)) . '">'
            . ProfileHtml::escape($comment->createdAt->setTimezone($this->timezone)->format('d.m.Y H:i'))
            . '</time></div><p>' . nl2br(ProfileHtml::escape($comment->body->source()), false) . '</p></article>';
    }

    private function reactionControls(): string
    {
        $html = '';
        foreach ([
            'like' => ['👍', 'Beğen'],
            'love' => ['❤️', 'Sevgi'],
            'haha' => ['😄', 'Haha'],
            'wow' => ['😮', 'Vay'],
            'sad' => ['😢', 'Üzgün'],
            'angry' => ['😠', 'Kızgın'],
        ] as $key => [$icon, $label]) {
            $html .= '<button type="button" data-profile-wall-reaction="' . ProfileHtml::escape($key)
                . '" aria-label="' . ProfileHtml::escape($label) . '">' . ProfileHtml::escape($icon)
                . '<span>' . ProfileHtml::escape($label) . '</span></button>';
        }
        $html .= '<button type="button" class="profile-wall-reaction-remove" data-profile-wall-reaction-remove>'
            . 'Tepkiyi kaldır</button>';

        return $html;
    }

    /**
     * @param array<string,User|null> $cache
     */
    private function author(?EntityId $userId, array &$cache): string
    {
        if ($userId === null) {
            return 'Silinmiş üye';
        }

        $key = $userId->value();
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $this->users->find($userId);
        }

        return $cache[$key]?->username()->display() ?? 'Silinmiş üye';
    }

    private function canPost(EntityId $viewerId, EntityId $profileOwnerId): bool
    {
        try {
            return $this->activity->canPost($viewerId, $profileOwnerId);
        } catch (PermissionDeniedException|ProfileActivityException|InvalidArgumentException) {
            return false;
        }
    }
}
