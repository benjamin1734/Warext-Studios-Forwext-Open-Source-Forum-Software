<?php

declare(strict_types=1);

namespace Forwext\App\Web\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Conversation\DirectConversationSummary;
use Forwext\Core\Conversation\DirectConversationView;
use Forwext\Core\Conversation\DirectMessage;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;

final class DirectConversationHtml
{
    /** @param list<DirectConversationSummary> $conversations */
    public static function inbox(
        array $conversations,
        string $csrfToken,
        BasePath $basePath,
        DateTimeZone $timezone,
        int $page,
        bool $hasMore,
    ): string {
        $list = '';
        foreach ($conversations as $conversation) {
            $href = $basePath->prepend(
                '/account/conversations/' . rawurlencode($conversation->conversationId->value()),
            );
            $list .= '<a class="conversation-row' . ($conversation->unreadCount > 0 ? ' is-unread' : '')
                . '" href="' . self::e($href) . '">'
                . '<span class="conversation-avatar" aria-hidden="true">'
                . self::initial($conversation->otherUsername) . '</span>'
                . '<span class="conversation-row-copy"><span><strong>' . self::e($conversation->otherUsername)
                . '</strong>' . ($conversation->unreadCount > 0
                    ? '<span class="conversation-unread">' . $conversation->unreadCount . '</span>'
                    : '') . '</span><small class="conversation-preview">'
                . self::e(self::preview($conversation->lastMessageBody)) . '</small></span>'
                . '<time datetime="' . self::e($conversation->updatedAt->format(DATE_ATOM)) . '">'
                . self::e(self::date($conversation->updatedAt, $timezone)) . '</time></a>';
        }
        if ($list === '') {
            $list = '<div class="surface-empty">Henüz özel konuşman yok.</div>';
        }

        $pagination = self::pagination($page, $hasMore, $basePath);
        $action = self::e($basePath->prepend('/account/conversations'));
        $body = '<section class="conversation-page discovery-page">'
            . '<header class="surface-head conversation-head"><div><span class="forum-eyebrow">MESAJLAR</span>'
            . '<h1>Özel Mesajlar</h1><p>Topluluk üyeleriyle yalnız katılımcıların görebildiği konuşmalar.</p></div></header>'
            . '<div class="conversation-layout"><section class="surface-panel conversation-list-panel">'
            . '<div class="conversation-list">' . $list . '</div>' . $pagination . '</section>'
            . '<aside class="surface-panel conversation-start"><h2>Yeni konuşma</h2>'
            . '<p>Bir kullanıcı adı ve ilk mesajı gir.</p>'
            . '<form method="post" action="' . $action . '">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="start">'
            . '<label><span>Kullanıcı adı</span><input name="recipient_username" maxlength="32" autocomplete="off" required></label>'
            . '<label><span>Mesaj</span><textarea name="body" maxlength="10000" rows="6" required></textarea></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Mesaj gönder</button>'
            . '</form></aside></div></section>';

        return ProfileHtml::page('Özel Mesajlar', $body, $basePath, authenticated: true);
    }

    public static function detail(
        DirectConversationView $view,
        EntityId $actorId,
        string $csrfToken,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $conversationId = $view->summary->conversationId->value();
        $messages = '';
        foreach ($view->messages as $message) {
            $messages .= self::message($message, $actorId, $basePath, $timezone);
        }
        if ($messages === '') {
            $messages = '<div class="surface-empty">Bu konuşmada henüz mesaj yok.</div>';
        }

        $action = self::e($basePath->prepend('/account/conversations/' . rawurlencode($conversationId)));
        $memberHref = self::e($basePath->prepend('/members/' . rawurlencode($view->summary->otherUsername)));
        $body = '<section class="conversation-page conversation-detail discovery-page">'
            . '<header class="surface-head conversation-head"><div>'
            . '<a class="surface-back-link" href="' . self::e($basePath->prepend('/account/conversations')) . '">← Mesajlar</a>'
            . '<span class="forum-eyebrow">ÖZEL KONUŞMA</span><h1>'
            . '<a href="' . $memberHref . '">' . self::e($view->summary->otherUsername) . '</a></h1>'
            . '<p>Bu konuşmayı yalnız katılımcılar görüntüleyebilir.</p></div></header>'
            . '<section class="surface-panel conversation-thread"><div class="conversation-message-list">'
            . $messages . '</div></section>'
            . '<section class="surface-panel conversation-reply"><h2>Yanıtla</h2>'
            . '<form method="post" action="' . $action . '">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="reply">'
            . '<label><span>Mesaj</span><textarea name="body" maxlength="10000" rows="6" required></textarea></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Gönder</button></form></section></section>';

        return ProfileHtml::page(
            'Konuşma · ' . $view->summary->otherUsername,
            $body,
            $basePath,
            authenticated: true,
        );
    }

    private static function message(
        DirectMessage $message,
        EntityId $actorId,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $own = $message->authorUserId?->equals($actorId) ?? false;
        $author = self::e($message->authorUsername);
        if ($message->authorUsername !== 'Silinmiş kullanıcı') {
            $author = '<a href="' . self::e($basePath->prepend('/members/' . rawurlencode($message->authorUsername)))
                . '">' . $author . '</a>';
        }

        return '<article class="conversation-message' . ($own ? ' is-own' : '') . '"><header><strong>'
            . $author . '</strong><time datetime="' . self::e($message->createdAt->format(DATE_ATOM)) . '">'
            . self::e(self::date($message->createdAt, $timezone)) . '</time></header><div>'
            . nl2br(self::e($message->body), false) . '</div></article>';
    }

    private static function pagination(int $page, bool $hasMore, BasePath $basePath): string
    {
        if ($page === 1 && !$hasMore) {
            return '';
        }
        $html = '<nav class="surface-pagination" aria-label="Mesaj sayfaları">';
        if ($page > 1) {
            $html .= '<a href="' . self::e($basePath->prepend('/account/conversations?page=' . ($page - 1)))
                . '">← Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a href="' . self::e($basePath->prepend('/account/conversations?page=' . ($page + 1)))
                . '">Sonraki →</a>';
        }
        return $html . '</nav>';
    }

    private static function preview(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (preg_match('/^(.{0,160})/us', $value, $match) !== 1) {
            return $value;
        }
        $preview = $match[1];
        return $preview === $value ? $preview : $preview . '…';
    }

    private static function initial(string $username): string
    {
        return ProfileHtml::initial($username);
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function date(DateTimeImmutable $value, DateTimeZone $timezone): string
    {
        return $value->setTimezone($timezone)->format('d.m.Y H:i');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
