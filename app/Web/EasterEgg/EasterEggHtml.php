<?php

declare(strict_types=1);

namespace Forwext\App\Web\EasterEgg;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\EasterEgg\EasterEggAnimation;
use Forwext\Core\EasterEgg\EasterEggDefinition;
use Forwext\Core\EasterEgg\EasterEggGroupOption;
use Forwext\Core\EasterEgg\EasterEggTriggerType;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;

final class EasterEggHtml
{
    /**
     * @param list<EasterEggDefinition> $definitions
     * @param list<EntityId> $selectedGroups
     * @param list<EasterEggGroupOption> $groupOptions
     */
    public static function manage(
        bool $globalEnabled,
        array $definitions,
        ?EasterEggDefinition $selected,
        array $selectedGroups,
        array $groupOptions,
        BasePath $basePath,
        string $csrfToken,
        bool $updated,
    ): string {
        $action = self::e($basePath->prepend('/admin/easter-eggs'));
        $selectedGroupMap = array_fill_keys(
            array_map(static fn (EntityId $id): string => $id->value(), $selectedGroups),
            true,
        );

        $notice = $updated
            ? '<div class="notice success">Easter Egg ayarları kaydedildi.</div>'
            : '';
        $killState = $globalEnabled
            ? '<strong>Açık.</strong> Etkin tanımlar eşleşen sayfalarda gösterilebilir.'
            : '<strong>Kapalı.</strong> Hiçbir Easter Egg kullanıcıya gösterilmez.';

        $body = '<section class="card"><h1>Easter Egg Yönetimi</h1>'
            . '<p class="muted">Sürpriz mesajları tarih, route/path ve kullanıcı gruplarına göre yönetin. '
            . 'Global anahtar acil kapatma mekanizmasıdır.</p>'
            . $notice
            . '<section class="section"><h2>Global güvenlik anahtarı</h2><p>' . $killState . '</p>'
            . '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="global_toggle">'
            . '<label><input type="checkbox" name="global_enabled" value="1"'
            . ($globalEnabled ? ' checked' : '') . '> Easter Egg runtime’ını global olarak etkinleştir</label>'
            . '<button type="submit">Global durumu kaydet</button></form></section>';

        $body .= '<section class="section"><h2>Tanımlar</h2>';
        if ($definitions === []) {
            $body .= '<div class="empty">Henüz Easter Egg tanımı yok.</div>';
        } else {
            $body .= '<div class="search-results">';
            foreach ($definitions as $definition) {
                $body .= '<article class="search-hit"><div class="search-hit-type">'
                    . ($definition->enabled ? 'Etkin' : 'Kapalı') . '</div>'
                    . '<h3><a href="' . self::e($basePath->prepend(
                        '/admin/easter-eggs?id=' . rawurlencode($definition->easterEggId->value()),
                    )) . '">' . self::e($definition->name) . '</a></h3>'
                    . '<p><code>' . self::e($definition->key) . '</code> · öncelik '
                    . $definition->priority . '</p>'
                    . '<p class="muted">Route: ' . self::e($definition->routeName ?? '—')
                    . ' · Path: ' . self::e($definition->pathPattern ?? '—') . '</p></article>';
            }
            $body .= '</div>';
        }
        $body .= '</section>';

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $starts = $selected?->startsAt;
        $ends = $selected?->endsAt;
        $trigger = $selected?->triggerType ?? EasterEggTriggerType::Automatic;
        $animation = $selected?->animation ?? EasterEggAnimation::None;

        $groupFields = '';
        foreach ($groupOptions as $option) {
            $checked = isset($selectedGroupMap[$option->groupId->value()]) ? ' checked' : '';
            $groupFields .= '<label><input type="checkbox" name="group_ids[]" value="'
                . self::e($option->groupId->value()) . '"' . $checked . '> '
                . self::e($option->name) . '</label>';
        }
        if ($groupFields === '') {
            $groupFields = '<p class="muted">Tanımlı kullanıcı grubu bulunmuyor.</p>';
        }

        $body .= '<section class="section"><h2>'
            . ($selected === null ? 'Yeni Easter Egg' : 'Tanımı düzenle')
            . '</h2><form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="save">'
            . '<input type="hidden" name="easter_egg_id" value="' . self::e($selected?->easterEggId->value() ?? '') . '">'
            . '<label><span>Anahtar</span><input name="key" maxlength="64" required value="'
            . self::e($selected?->key ?? '') . '" placeholder="gizli-surpriz"></label>'
            . '<label><span>Yönetim adı</span><input name="name" maxlength="100" required value="'
            . self::e($selected?->name ?? '') . '"></label>'
            . '<label><span>Öncelik</span><input type="number" name="priority" min="0" max="65535" value="'
            . ($selected?->priority ?? 100) . '"></label>'
            . '<label><input type="checkbox" name="enabled" value="1"'
            . (($selected?->enabled ?? false) ? ' checked' : '') . '> Bu tanımı etkinleştir</label>'
            . '<label class="search-wide"><span>Sürpriz mesaj</span><textarea name="message" maxlength="1000" rows="5" required>'
            . self::e($selected?->message ?? '') . '</textarea></label>'
            . '<label><span>Görsel rozet etiketi</span><input name="badge_label" maxlength="40" value="'
            . self::e($selected?->badgeLabel ?? '') . '" placeholder="Sürpriz!"></label>'
            . '<label><span>Animasyon</span><select name="animation">'
            . self::option('none', 'Yok', $animation->value)
            . self::option('pulse', 'Pulse', $animation->value)
            . self::option('glow', 'Glow', $animation->value)
            . self::option('confetti', 'Confetti', $animation->value)
            . '</select></label>'
            . '<label><span>Başlangıç (UTC, opsiyonel)</span><input type="datetime-local" name="starts_at" value="'
            . self::e($starts?->format('Y-m-d\TH:i') ?? '') . '"></label>'
            . '<label><span>Bitiş (UTC, opsiyonel)</span><input type="datetime-local" name="ends_at" value="'
            . self::e($ends?->format('Y-m-d\TH:i') ?? '') . '"></label>'
            . '<label><span>Trigger</span><select name="trigger_type">'
            . self::option('automatic', 'Otomatik', $trigger->value)
            . self::option('query_token', 'URL query token', $trigger->value)
            . '</select></label>'
            . '<label><span>Query token</span><input name="trigger_value" maxlength="64" value="'
            . self::e($selected?->triggerValue ?? '') . '" placeholder="surpriz2026"></label>'
            . '<label><span>Route adı</span><input name="route_name" maxlength="128" value="'
            . self::e($selected?->routeName ?? '') . '" placeholder="giveaway.index"></label>'
            . '<label><span>Path pattern</span><input name="path_pattern" maxlength="255" value="'
            . self::e($selected?->pathPattern ?? '') . '" placeholder="/giveaways/*"></label>'
            . '<p class="muted search-wide">Route ve path birlikte girilirse ikisi de eşleşmelidir. '
            . 'Path sonunda tek <code>*</code> prefix eşleşmesi için kullanılabilir. Query token bir parola değildir; URL içinde görünür.</p>'
            . '<details class="search-wide"><summary>Gelişmiş: grup görünürlüğü</summary>'
            . '<p class="muted">Hiç grup seçilmezse tanım misafirler dahil route/path’e erişebilen herkese görünür. '
            . 'Grup seçilirse kullanıcının primary veya secondary gruplarından en az biri eşleşmelidir.</p>'
            . '<div class="grid">' . $groupFields . '</div></details>'
            . '<div class="search-actions"><button type="submit">'
            . ($selected === null ? 'Tanımı oluştur' : 'Tanımı kaydet')
            . '</button><a href="' . self::e($basePath->prepend('/admin/easter-eggs')) . '">Yeni tanım</a></div>'
            . '</form></section></section>';

        return ProfileHtml::page('Easter Egg Yönetimi', $body, $basePath, authenticated:true);
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function option(string $value, string $label, string $selected): string
    {
        return '<option value="' . self::e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
            . self::e($label) . '</option>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
