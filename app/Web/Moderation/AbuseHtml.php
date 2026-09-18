<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Abuse\AbuseEvent;
use Forwext\Core\Moderation\Abuse\AbuseOverview;
use Forwext\Core\Moderation\Abuse\AbuseRule;
use Forwext\Core\Routing\BasePath;

final class AbuseHtml
{
    public static function page(
        AbuseOverview $overview,
        BasePath $basePath,
        AbuseCapabilities $capabilities,
    ): string {
        $rules = '';
        foreach ($overview->rules as $rule) {
            $rules .= self::rule($rule);
        }
        if ($rules === '') {
            $rules = '<div class="empty">Aktif anti-abuse kuralı yok.</div>';
        }

        $events = '';
        foreach ($overview->events as $event) {
            $events .= self::event($event, $capabilities->canCleanup);
        }
        if ($events === '') {
            $events = '<div class="empty">İnceleme bekleyen abuse olayı yok.</div>';
        }

        $ruleEditor = '';
        if ($capabilities->canManageRules) {
            $ruleEditor = '<details class="card section"><summary><strong>Gelişmiş: otomatik kural oluştur/güncelle</strong></summary>'
                . '<form method="post" action="' . self::e($basePath->prepend('/moderation/abuse/rules')) . '" '
                . 'data-moderation-form class="search-form">'
                . '<label><span>Anahtar</span><input name="rule_key" maxlength="64" required></label>'
                . '<label><span>Başlık</span><input name="label" maxlength="120" required></label>'
                . '<label><span>Olay</span><select name="event_type"><option value="registration">Kayıt</option>'
                . '<option value="thread">Konu</option><option value="post">Mesaj</option></select></label>'
                . '<label><span>Sinyal</span><select name="signal_key"><option value="user">Kullanıcı</option>'
                . '<option value="identity">Kimlik</option><option value="ip">IP fingerprint</option>'
                . '<option value="device">Cihaz fingerprint</option><option value="content">İçerik fingerprint</option></select></label>'
                . '<label><span>İzin verilen hit</span><input name="hit_limit" value="5" inputmode="numeric" required></label>'
                . '<label><span>Pencere (sn)</span><input name="window_seconds" value="60" inputmode="numeric" required></label>'
                . '<label><span>Karar</span><select name="action"><option value="review">İnceleme</option>'
                . '<option value="reject">Engelle</option></select></label>'
                . '<label><span>Durum</span><select name="active"><option value="1">Aktif</option>'
                . '<option value="0">Pasif</option></select></label>'
                . '<label><span>Öncelik</span><input name="priority" value="100" inputmode="numeric" required></label>'
                . '<div class="search-actions"><button type="submit">Kuralı kaydet</button></div></form></details>';
        }

        $eventOpen = $capabilities->canCleanup
            ? '<form method="post" action="' . self::e($basePath->prepend('/moderation/abuse/events')) . '" data-moderation-form>'
            : '';
        $eventClose = '';
        if ($capabilities->canCleanup) {
            $eventClose = '<div class="card section"><label><span>Neden kodu</span>'
                . '<input name="reason_code" maxlength="64" value="abuse.spam_cleanup" required></label>'
                . '<div class="search-actions"><button name="action" value="cleanup" type="submit">Seçili içeriği temizle</button>'
                . '<button name="action" value="dismiss" type="submit">Seçilileri kapat</button></div></div></form>';
        }

        $script = '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';
        $content = '<div class="card"><h1 style="margin:0">Anti-spam / Abuse</h1>'
            . '<p class="muted">Flood ve abuse kararları IP, cihaz, kullanıcı, kimlik ve içerik fingerprint sinyallerini '
            . 'ham hassas veri saklamadan değerlendirir. Cleanup mevcut content-moderation permission ve audit zincirini kullanır.</p></div>'
            . '<section class="card section"><h2>Aktif kurallar</h2>' . $rules . '</section>'
            . $ruleEditor
            . '<section class="card section"><h2>İnceleme olayları</h2></section>'
            . $eventOpen . '<section class="card section">' . $events . '</section>' . $eventClose
            . $script;

        return ProfileHtml::page('Anti-spam / Abuse', $content, $basePath, authenticated: true);
    }

    private static function rule(AbuseRule $rule): string
    {
        return '<article class="search-hit"><span class="search-hit-type">' . self::e($rule->eventType->label()) . '</span>'
            . '<h3>' . self::e($rule->label) . '</h3>'
            . '<div class="muted">' . self::e($rule->key) . ' · ' . self::e($rule->signal->label())
            . ' · limit ' . $rule->limit . ' / ' . $rule->windowSeconds . ' sn · '
            . self::e($rule->action->value) . ' · öncelik ' . $rule->priority
            . ' · ' . ($rule->active ? 'aktif' : 'pasif') . '</div></article>';
    }

    private static function event(AbuseEvent $event, bool $selectable): string
    {
        $check = $selectable
            ? '<label class="muted"><input type="checkbox" name="events[]" value="'
                . self::e($event->eventId->value()) . '"> Seç</label>'
            : '';
        $target = $event->targetType === null
            ? 'Hedef oluşturulmadan engellendi'
            : self::e($event->targetType . ':' . $event->targetId?->value());
        $actor = $event->actorUserId?->value() ?? 'anonim/kayıt öncesi';

        return '<article class="search-hit"><span class="search-hit-type">' . self::e($event->eventType->label()) . '</span>'
            . '<h3>' . self::e($event->decision->value) . '</h3>'
            . '<div class="muted">Kurallar: ' . self::e(implode(', ', $event->matchedRuleKeys)) . '</div>'
            . '<div class="muted">Aktör: ' . self::e($actor) . ' · Hedef: ' . $target . '</div>'
            . '<div class="muted">' . self::e($event->occurredAt->format('Y-m-d H:i')) . ' UTC</div>'
            . $check . '</article>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
