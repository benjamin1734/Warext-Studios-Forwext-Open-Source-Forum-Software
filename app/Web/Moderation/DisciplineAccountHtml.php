<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Discipline\DisciplineAction;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineRestrictionKey;
use Forwext\Core\Routing\BasePath;

final class DisciplineAccountHtml
{
    /** @param list<DisciplineAction> $actions */
    public static function page(array $actions, int $activePoints, DateTimeImmutable $now, BasePath $basePath): string
    {
        $rows = '';
        foreach ($actions as $action) {
            $extra = '';
            if ($action->type === DisciplineActionType::Warning) {
                $extra .= ' · ' . $action->points . ' puan';
            }
            if ($action->type === DisciplineActionType::Restriction) {
                $extra .= ' · ' . self::e(implode(', ', array_map(
                    static fn (DisciplineRestrictionKey $key): string => $key->label(),
                    $action->restrictions,
                )));
            }
            if ($action->expiresAt !== null) {
                $extra .= ' · Bitiş: ' . self::e($action->expiresAt->format('Y-m-d H:i')) . ' UTC';
            } elseif (in_array($action->type, [DisciplineActionType::Restriction, DisciplineActionType::Ban], true)) {
                $extra .= ' · Kalıcı';
            }
            $appeal = $action->appealReference();

            $rows .= '<article class="search-hit"><span class="search-hit-type">'
                . self::e($action->type->label()) . '</span>'
                . '<h3>' . self::e($action->reasonCode->value()) . '</h3>'
                . '<p>' . self::e($action->reasonText) . '</p>'
                . '<div class="muted">Durum: ' . self::e($action->statusAt($now))
                . ' · Başlangıç: ' . self::e($action->startsAt->format('Y-m-d H:i')) . ' UTC' . $extra . '</div>'
                . ($appeal === null ? '' : '<div class="muted">İtiraz referansı: ' . self::e($appeal) . '</div>')
                . '</article>';
        }
        if ($rows === '') {
            $rows = '<div class="empty">Hesabınızda disiplin kaydı bulunmuyor.</div>';
        }

        $content = '<div class="card"><h1 style="margin:0">Disiplin kayıtlarım</h1>'
            . '<p class="muted">Aktif uyarı puanınız: <strong>' . $activePoints . '</strong>. '
            . 'İtiraz referansı, destek/itiraz entegrasyonlarının bu kayıtla güvenli biçimde eşleşmesi için sabit kimliktir.</p></div>'
            . '<section class="card section"><h2>Geçmiş</h2>' . $rows . '</section>';

        return ProfileHtml::page('Disiplin kayıtlarım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
