<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Giveaway\Giveaway;
use Forwext\Core\Giveaway\GiveawayState;
use Forwext\Core\Routing\BasePath;

final class GiveawayHtml
{
    /** @param list<Giveaway> $giveaways */
    public static function index(
        array $giveaways,
        BasePath $basePath,
        bool $canCreate,
    ): string {
        $actions = $canCreate
            ? '<p><a href="' . self::e($basePath->prepend('/giveaways/manage')) . '">Yeni çekiliş oluştur</a></p>'
            : '';

        $body = '<section class="card"><h1>Çekilişler</h1>'
            . '<p class="muted">Aktif, planlanmış ve tamamlanmış topluluk çekilişleri.</p>'
            . $actions;

        if ($giveaways === []) {
            $body .= '<div class="empty">Görüntülenebilir çekiliş bulunmuyor.</div>';
        } else {
            $body .= '<div class="search-results">';
            foreach ($giveaways as $giveaway) {
                $body .= self::card($giveaway, $basePath);
            }
            $body .= '</div>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Çekilişler', $body, $basePath, authenticated:true);
    }

    public static function detail(
        Giveaway $giveaway,
        BasePath $basePath,
        bool $canManage,
    ): string {
        $body = '<article class="card"><div class="search-hit-type">'
            . self::e(self::stateLabel($giveaway->state))
            . '</div><h1>' . self::e($giveaway->title) . '</h1>'
            . '<p class="muted">Başlangıç: ' . self::e(self::date($giveaway->startsAt))
            . ' · Bitiş: ' . self::e(self::date($giveaway->endsAt)) . '</p>'
            . '<section class="section"><h2>Ödül</h2><p><strong>'
            . self::e($giveaway->prize->title) . '</strong> × ' . $giveaway->prize->quantity . '</p>';

        if ($giveaway->prize->description !== '') {
            $body .= '<div class="about">' . nl2br(self::e($giveaway->prize->description), false) . '</div>';
        }
        $body .= '</section><section class="section"><h2>Açıklama</h2><div class="about">'
            . nl2br(self::e($giveaway->description), false)
            . '</div></section><section class="section"><h2>Katılım koşulları</h2><div class="about">'
            . nl2br(self::e($giveaway->participationTerms), false)
            . '</div><p class="muted">Kişi başı hak: ' . $giveaway->entriesPerUser
            . ' · Maksimum katılımcı: '
            . ($giveaway->maxParticipants === null ? 'Sınırsız' : (string) $giveaway->maxParticipants)
            . '</p></section>';

        if ($canManage) {
            $body .= '<p><a href="' . self::e($basePath->prepend(
                '/giveaways/manage?giveaway=' . rawurlencode($giveaway->giveawayId->value()),
            )) . '">Çekilişi yönet</a></p>';
        }
        $body .= '</article>';

        return ProfileHtml::page($giveaway->title, $body, $basePath, authenticated:true);
    }

    /** @param list<Giveaway> $giveaways */
    public static function manage(
        array $giveaways,
        ?Giveaway $giveaway,
        BasePath $basePath,
        string $csrfToken,
        bool $updated,
    ): string {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $starts = $giveaway?->startsAt ?? $now->modify('+1 hour');
        $ends = $giveaway?->endsAt ?? $now->modify('+1 day');
        $action = self::e($basePath->prepend('/giveaways/manage'));
        $notice = $updated ? '<div class="notice success">Çekiliş işlemi kaydedildi.</div>' : '';

        $body = '<section class="card"><h1>Çekiliş Yönetimi</h1>'
            . '<p class="muted">Bu ekran zamanlama ve yaşam döngüsünü yönetir. Uygunluk/katılım 13.04, kazanan seçimi 13.05 kapsamındadır.</p>'
            . $notice
            . '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="sync_due">'
            . '<button type="submit">Zamanı gelen durumları senkronize et</button></form>';

        if ($giveaways !== []) {
            $body .= '<section class="section"><h2>Yönetilebilir çekilişler</h2><div class="search-results">';
            foreach ($giveaways as $item) {
                $body .= self::card($item, $basePath, true);
            }
            $body .= '</div></section>';
        }

        $editable = $giveaway === null
            || in_array($giveaway->state, [GiveawayState::Draft, GiveawayState::Scheduled], true);

        if ($editable) {
            $body .= '<section class="section"><h2>'
                . ($giveaway === null ? 'Yeni çekiliş' : 'Çekilişi düzenle')
                . '</h2><form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="save">'
                . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway?->giveawayId->value() ?? '') . '">'
                . '<label><span>Slug</span><input name="slug" maxlength="160" required value="'
                . self::e($giveaway?->slug ?? '') . '"></label>'
                . '<label class="search-wide"><span>Başlık</span><input name="title" maxlength="180" required value="'
                . self::e($giveaway?->title ?? '') . '"></label>'
                . '<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="100000" rows="10" required>'
                . self::e($giveaway?->description ?? '') . '</textarea></label>'
                . '<label class="search-wide"><span>Ödül adı</span><input name="prize_title" maxlength="180" required value="'
                . self::e($giveaway?->prize->title ?? '') . '"></label>'
                . '<label class="search-wide"><span>Ödül açıklaması</span><textarea name="prize_description" maxlength="2000" rows="4">'
                . self::e($giveaway?->prize->description ?? '') . '</textarea></label>'
                . '<label><span>Ödül adedi</span><input type="number" name="prize_quantity" min="1" max="1000000" value="'
                . ($giveaway?->prize->quantity ?? 1) . '" required></label>'
                . '<label class="search-wide"><span>Katılım koşulları</span><textarea name="participation_terms" maxlength="20000" rows="8" required>'
                . self::e($giveaway?->participationTerms ?? '') . '</textarea></label>'
                . '<label><span>Başlangıç (UTC)</span><input type="datetime-local" name="starts_at" value="'
                . self::e($starts->format('Y-m-d\TH:i')) . '" required></label>'
                . '<label><span>Bitiş (UTC)</span><input type="datetime-local" name="ends_at" value="'
                . self::e($ends->format('Y-m-d\TH:i')) . '" required></label>'
                . '<label><span>Kişi başı hak</span><input type="number" name="entries_per_user" min="1" max="1000" value="'
                . ($giveaway?->entriesPerUser ?? 1) . '" required></label>'
                . '<label><span>Maksimum katılımcı</span><input type="number" name="max_participants" min="1" max="100000000" value="'
                . self::e($giveaway?->maxParticipants === null ? '' : (string) $giveaway->maxParticipants)
                . '" placeholder="Sınırsız"></label>'
                . '<div class="search-actions"><button type="submit">Taslak olarak kaydet</button></div></form></section>';
        }

        if ($giveaway !== null) {
            $body .= '<section class="section"><h2>Yaşam döngüsü</h2><p class="muted">Mevcut durum: '
                . self::e(self::stateLabel($giveaway->state)) . '</p>';
            if (in_array($giveaway->state, [GiveawayState::Draft, GiveawayState::Scheduled], true)) {
                $body .= self::actionForm($action, $csrfToken, 'publish', $giveaway, 'Yayımla / zamanla');
            }
            if (!$giveaway->state->isTerminal()) {
                $body .= self::actionForm($action, $csrfToken, 'cancel', $giveaway, 'İptal et');
            }
            $body .= '</section>';
        }

        $body .= '</section>';
        return ProfileHtml::page('Çekiliş Yönetimi', $body, $basePath, authenticated:true);
    }

    private static function actionForm(
        string $action,
        string $csrfToken,
        string $operation,
        Giveaway $giveaway,
        string $label,
    ): string {
        return '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="' . self::e($operation) . '">'
            . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway->giveawayId->value()) . '">'
            . '<button type="submit">' . self::e($label) . '</button></form>';
    }

    private static function card(Giveaway $giveaway, BasePath $basePath, bool $manage = false): string
    {
        $path = $manage
            ? '/giveaways/manage?giveaway=' . rawurlencode($giveaway->giveawayId->value())
            : '/giveaways/' . rawurlencode($giveaway->giveawayId->value());
        return '<article class="search-hit"><div class="search-hit-type">'
            . self::e(self::stateLabel($giveaway->state)) . '</div><h2><a href="'
            . self::e($basePath->prepend($path)) . '">' . self::e($giveaway->title) . '</a></h2>'
            . '<p>' . self::e($giveaway->prize->title) . ' × ' . $giveaway->prize->quantity . '</p>'
            . '<p class="muted">' . self::e(self::date($giveaway->startsAt))
            . ' → ' . self::e(self::date($giveaway->endsAt)) . '</p></article>';
    }

    private static function stateLabel(GiveawayState $state): string
    {
        return match ($state) {
            GiveawayState::Draft => 'Taslak',
            GiveawayState::Scheduled => 'Planlandı',
            GiveawayState::Open => 'Aktif',
            GiveawayState::Closed => 'Kapandı',
            GiveawayState::Cancelled => 'İptal edildi',
        };
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i') . ' UTC';
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
