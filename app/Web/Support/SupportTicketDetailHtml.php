<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Faq\SupportBridge\FaqSupportRecommendation;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Conversation\SupportConversationMessage;
use Forwext\Core\Support\Conversation\SupportConversationView;
use Forwext\Core\Support\Conversation\SupportHistoryEventType;
use Forwext\Core\Support\Conversation\SupportMessageRole;
use Forwext\Core\Support\Conversation\SupportMessageVisibility;
use Forwext\Core\Support\Conversation\SupportTicketHistoryEntry;
use Forwext\Core\Support\Conversation\SupportTicketRelation;
use Forwext\Core\Support\Conversation\SupportTicketRelationType;
use Forwext\Core\Support\Intake\SupportAttachmentRecord;
use Forwext\Core\Support\Intake\SupportContextLink;
use Forwext\Core\Support\Intake\SupportFieldValue;
use Forwext\Core\Support\Ticket\SupportTicketStatus;

final class SupportTicketDetailHtml
{
    /**
     * @param array<string,SupportFieldValue> $fieldValues
     * @param list<SupportAttachmentRecord> $attachments
     * @param list<FaqSupportRecommendation> $faqRecommendations
     */
    public static function page(
        SupportConversationView $view,
        ?string $description,
        array $fieldValues,
        ?SupportContextLink $context,
        array $attachments,
        string $csrfToken,
        BasePath $basePath,
        SupportTicketDetailCapabilities $capabilities,
        ?string $requesterName = null,
        ?string $assigneeName = null,
        bool $updated = false,
        array $faqRecommendations = [],
    ): string {
        $ticket = $view->ticket;
        $notice = $updated ? '<div class="notice success">Talep güncellendi.</div>' : '';
        $meta = '<div class="profile-stats">'
            . self::stat('Durum', $ticket->status->label())
            . self::stat('Öncelik', $ticket->priority->label())
            . self::stat('Kategori', $ticket->categoryKey)
            . self::stat('Talep sahibi', $requesterName ?? 'Silinmiş hesap')
            . self::stat('Atanan', $assigneeName ?? 'Atanmamış')
            . ($view->escalation === null ? '' : self::stat('Escalation', 'Seviye ' . $view->escalation->level))
            . '</div>';

        $intake = '<section class="card section"><h2>Talep</h2>'
            . '<p>' . self::multiline($description ?? 'Açıklama bulunmuyor.') . '</p>';
        if ($fieldValues !== []) {
            $intake .= '<dl class="detail-list">';
            foreach ($fieldValues as $key => $value) {
                $shown = is_bool($value->value) ? ($value->value ? 'Evet' : 'Hayır') : $value->value;
                $intake .= '<dt>' . self::e($key) . '</dt><dd>' . self::e((string) $shown) . '</dd>';
            }
            $intake .= '</dl>';
        }
        if ($context !== null) {
            $intake .= '<p><strong>Bağlam:</strong> ' . self::e($context->type->label())
                . ' — ' . self::e($context->labelSnapshot) . '</p>';
        }
        if ($attachments !== []) {
            $intake .= '<div><strong>Ek dosyalar</strong><ul>';
            foreach ($attachments as $attachment) {
                $href = $basePath->prepend(
                    '/support/tickets/' . rawurlencode($ticket->ticketId->value())
                    . '/attachments/' . rawurlencode($attachment->attachmentId->value()),
                );
                $intake .= '<li><a href="' . self::e($href) . '">' . self::e($attachment->filename->value())
                    . '</a> <span class="muted">(' . self::e($attachment->mediaType) . ', '
                    . self::e(self::bytes($attachment->sizeBytes)) . ')</span></li>';
            }
            $intake .= '</ul></div>';
        }
        $intake .= '</section>';

        $relations = self::relations($view->relations, $ticket->ticketId->value(), $basePath);
        $messages = '<section class="card section"><h2>Konuşma</h2>';
        if ($view->messages === []) {
            $messages .= '<p class="muted">Henüz mesaj yok.</p>';
        } else {
            foreach ($view->messages as $message) {
                $messages .= self::message($message, $csrfToken, $basePath, $capabilities);
            }
        }
        $messages .= '</section>';

        $reply = '';
        if ($capabilities->canReply && $ticket->status !== SupportTicketStatus::Closed) {
            $canned = '';
            if ($view->staffView && $view->cannedResponses !== []) {
                $canned = '<label><span>Hazır cevap</span><select name="canned_response_key">'
                    . '<option value="">Kullanma</option>';
                foreach ($view->cannedResponses as $response) {
                    $canned .= '<option value="' . self::e($response->key) . '">' . self::e($response->title) . '</option>';
                }
                $canned .= '</select></label>';
            }
            $reply = '<section class="card section"><h2>Yanıt yaz</h2>'
                . '<form method="post" action="' . self::action($ticket->ticketId->value(), $basePath) . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="reply">'
                . $canned
                . '<label><span>Mesaj</span><textarea name="body" maxlength="10000" rows="7"'
                . ($view->staffView && $view->cannedResponses !== [] ? '' : ' required') . '></textarea></label>'
                . '<button type="submit">Yanıt gönder</button></form></section>';
        }

        $staffTools = $view->staffView
            ? self::staffTools($view, $csrfToken, $basePath, $capabilities)
            : '';

        $faqGuidance = $faqRecommendations === []
            ? ''
            : FaqRecommendationHtml::section(
                $faqRecommendations,
                $basePath,
                'Çözüm sonrası ilgili SSS',
                'Bu talep için görünür bir SSS önerisi bulunamadı.',
            );

        $history = '<section class="card section"><h2>Durum geçmişi</h2>';
        if ($view->history === []) {
            $history .= '<p class="muted">Henüz durum değişikliği yok.</p>';
        } else {
            $history .= '<ul>';
            foreach ($view->history as $entry) {
                $history .= '<li>' . self::e($entry->createdAt->format('Y-m-d H:i'))
                    . ' — ' . self::e(self::historyText($entry)) . '</li>';
            }
            $history .= '</ul>';
        }
        $history .= '</section>';

        $body = '<section class="card settings"><h1>' . self::e($ticket->subject) . '</h1>'
            . '<p class="muted">Talep #' . self::e($ticket->ticketId->value()) . '</p>'
            . $notice . $meta . '</section>'
            . $relations . $intake . $messages . $faqGuidance . $reply . $staffTools . $history;

        return ProfileHtml::page('Destek talebi', $body, $basePath, authenticated: true);
    }

    private static function message(
        SupportConversationMessage $message,
        string $csrfToken,
        BasePath $basePath,
        SupportTicketDetailCapabilities $capabilities,
    ): string {
        $role = $message->authorRole === SupportMessageRole::Staff ? 'Yetkili' : 'Kullanıcı';
        $internal = $message->visibility === SupportMessageVisibility::Internal
            ? ' <strong>[Internal note]</strong>'
            : '';
        $canned = $message->cannedResponseTitleSnapshot === null
            ? ''
            : ' <span class="muted">Hazır cevap: ' . self::e($message->cannedResponseTitleSnapshot) . '</span>';
        $copy = $message->copiedFromMessageId === null
            ? ''
            : ' <span class="muted">Ayrılan mesaj kopyası</span>';

        $faqDraft = '';
        if ($capabilities->canSuggestFaqDraft
            && $message->authorRole === SupportMessageRole::Staff
            && $message->visibility === SupportMessageVisibility::Public
        ) {
            $faqDraft = '<details><summary>Bu yanıttan SSS taslağı öner</summary>'
                . '<form method="post" action="' . self::action($message->ticketId->value(), $basePath) . '">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="faq_draft">'
                . '<input type="hidden" name="message_id" value="' . self::e($message->messageId->value()) . '">'
                . '<label><span>SSS kategori anahtarı (isteğe bağlı)</span><input name="faq_category_key" maxlength="64"></label>'
                . '<button type="submit">SSS taslağı öner</button></form></details>';
        }

        $split = '';
        if ($capabilities->canSplit && $message->visibility === SupportMessageVisibility::Public) {
            $split = '<details><summary>Bu mesajdan yeni talep ayır</summary>'
                . '<form method="post" action="' . self::action($message->ticketId->value(), $basePath) . '">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="split">'
                . '<input type="hidden" name="message_id" value="' . self::e($message->messageId->value()) . '">'
                . '<label><span>Yeni talep başlığı</span><input name="subject" maxlength="200" required></label>'
                . '<button type="submit">Yeni talep oluştur</button></form></details>';
        }

        return '<article class="support-message"><header><strong>' . self::e($role) . '</strong>'
            . $internal . $canned . $copy
            . '<span class="muted"> — ' . self::e($message->createdAt->format('Y-m-d H:i')) . '</span></header>'
            . '<p>' . self::multiline($message->body) . '</p>' . $faqDraft . $split . '</article>';
    }

    private static function staffTools(
        SupportConversationView $view,
        string $csrfToken,
        BasePath $basePath,
        SupportTicketDetailCapabilities $capabilities,
    ): string {
        $ticketId = $view->ticket->ticketId->value();
        $action = self::action($ticketId, $basePath);
        $body = '<section class="card section"><h2>Yetkili araçları</h2>';

        if ($capabilities->canInternalNote) {
            $body .= '<details><summary>Internal note ekle</summary><form method="post" action="' . $action . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="note">'
                . '<label><span>Not</span><textarea name="body" maxlength="10000" rows="5" required></textarea></label>'
                . '<button type="submit">Notu ekle</button></form></details>';
        }

        if ($capabilities->canAssign && $view->ticket->status->isActive()) {
            $body .= '<details><summary>Atama</summary><form method="post" action="' . $action . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="assign">'
                . '<label><span>Kullanıcı adı</span><input name="assignee_username" maxlength="32" placeholder="Boş bırakırsan atama kaldırılır"></label>'
                . '<button type="submit">Atamayı kaydet</button></form></details>';
        }

        if ($capabilities->canManageStatus) {
            $body .= '<details><summary>Durum değiştir</summary><form method="post" action="' . $action . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="status">'
                . '<label><span>Durum</span><select name="status">';
            foreach (SupportTicketStatus::cases() as $status) {
                $body .= '<option value="' . self::e($status->value) . '"'
                    . ($view->ticket->status === $status ? ' selected' : '') . '>'
                    . self::e($status->label()) . '</option>';
            }
            $body .= '</select></label><button type="submit">Durumu güncelle</button></form></details>';
        }

        if ($capabilities->canEscalate && $view->ticket->status->isActive()) {
            $current = $view->escalation?->level ?? 0;
            if ($current < 5) {
                $body .= '<details><summary>Escalation</summary><form method="post" action="' . $action . '">'
                    . self::csrf($csrfToken) . '<input type="hidden" name="action" value="escalate">'
                    . '<label><span>Seviye</span><select name="level">';
                for ($level = $current + 1; $level <= 5; $level++) {
                    $body .= '<option value="' . $level . '">' . $level . '</option>';
                }
                $body .= '</select></label><button type="submit">Escalate et</button></form></details>';
            }
        }

        if ($capabilities->canMerge && $view->ticket->status->isActive()) {
            $body .= '<details><summary>Başka talebe birleştir</summary><form method="post" action="' . $action . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="merge">'
                . '<label><span>Hedef talep kimliği</span><input name="target_ticket_id" maxlength="32" pattern="[a-f0-9]{32}" required></label>'
                . '<p class="muted">Güvenlik nedeniyle yalnız aynı kullanıcıya ait iki aktif talep birleştirilebilir.</p>'
                . '<button type="submit">Birleştir</button></form></details>';
        }

        if ($capabilities->canManageCanned) {
            $body .= '<details><summary>Hazır cevap kaydet</summary><form method="post" action="' . $action . '">'
                . self::csrf($csrfToken) . '<input type="hidden" name="action" value="canned_save">'
                . '<label><span>Anahtar</span><input name="key" maxlength="64" pattern="[a-z][a-z0-9._-]{1,63}" required></label>'
                . '<label><span>Başlık</span><input name="title" maxlength="120" required></label>'
                . '<label><span>Metin</span><textarea name="body" maxlength="10000" rows="5" required></textarea></label>'
                . '<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="100"></label>'
                . '<label><input type="checkbox" name="active" value="1" checked> Aktif</label>'
                . '<button type="submit">Hazır cevabı kaydet</button></form></details>';
        }

        return $body . '</section>';
    }

    /**
     * @param list<SupportTicketRelation> $relations
     */
    private static function relations(array $relations, string $currentTicketId, BasePath $basePath): string
    {
        if ($relations === []) {
            return '';
        }
        $html = '<section class="card section"><h2>İlişkili talepler</h2><ul>';
        foreach ($relations as $relation) {
            $otherId = $relation->sourceTicketId->value() === $currentTicketId
                ? $relation->targetTicketId->value()
                : $relation->sourceTicketId->value();
            $href = $basePath->prepend('/support/tickets/' . rawurlencode($otherId));
            $label = match ($relation->type) {
                SupportTicketRelationType::MergedInto => $relation->sourceTicketId->value() === $currentTicketId
                    ? 'Birleştirildiği talep'
                    : 'Bu talebe birleştirilen talep',
                SupportTicketRelationType::SplitFrom => $relation->sourceTicketId->value() === $currentTicketId
                    ? 'Bu talepten ayrılan talep'
                    : 'Kaynak talep',
            };
            $html .= '<li>' . self::e($label) . ': <a href="' . self::e($href) . '">#'
                . self::e($otherId) . '</a></li>';
        }
        return $html . '</ul></section>';
    }

    private static function historyText(SupportTicketHistoryEntry $entry): string
    {
        return match ($entry->eventType) {
            SupportHistoryEventType::Created => 'Talep oluşturuldu; başlangıç durumu: '
                . (string) ($entry->payload['status'] ?? 'open') . '.',
            SupportHistoryEventType::StatusChanged => 'Durum: '
                . (string) ($entry->payload['from'] ?? '?') . ' → ' . (string) ($entry->payload['to'] ?? '?'),
            SupportHistoryEventType::Assigned => 'Atama güncellendi.',
            SupportHistoryEventType::Escalated => 'Escalation seviyesi '
                . (string) ($entry->payload['to_level'] ?? '?') . ' oldu.',
            SupportHistoryEventType::Merged => 'Talep birleştirme ilişkisi oluşturuldu.',
            SupportHistoryEventType::SplitCreated => 'Talep ayırma ilişkisi oluşturuldu.',
            SupportHistoryEventType::FirstResponse => 'İlk yetkili yanıtı SLA zamanı kaydedildi.',
        };
    }

    private static function stat(string $label, string $value): string
    {
        return '<div><strong>' . self::e($label) . '</strong><span>' . self::e($value) . '</span></div>';
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function action(string $ticketId, BasePath $basePath): string
    {
        return self::e($basePath->prepend('/support/tickets/' . rawurlencode($ticketId)));
    }

    private static function multiline(string $value): string
    {
        return nl2br(self::e($value), false);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MiB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }
        return $bytes . ' B';
    }
}
