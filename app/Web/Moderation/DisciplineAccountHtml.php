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
                $extra .= ' · Bitiş · ' . self::e($action->expiresAt->format('Y-m-d H:i')) . ' UTC';
            } elseif (in_array($action->type, [DisciplineActionType::Restriction, DisciplineActionType::Ban], true)) {
                $extra .= ' · Kalıcı';
            }
            $appeal = $action->appealReference();

            $rows .= '<article class="discipline-account-row"><span class="discipline-account-type">'
                . self::e($action->type->label()) . '</span><div class="discipline-account-copy"><strong>'
                . self::e($action->reasonCode->value()) . '</strong><p>' . self::e($action->reasonText) . '</p>'
                . '<small>Durum · ' . self::e($action->statusAt($now))
                . ' · Başlangıç · ' . self::e($action->startsAt->format('Y-m-d H:i')) . ' UTC' . $extra . '</small>'
                . ($appeal === null ? '' : '<small>İtiraz referansı · ' . self::e($appeal) . '</small>')
                . '</div></article>';
        }

        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Disiplin kaydı yok.</strong>'
                . '<span>Hesabında görünür bir disiplin işlemi bulunmuyor.</span></div>';
        }

        $content = '<section class="discipline-account-page discovery-page"><header class="surface-head discipline-account-head"><div>'
            . '<span class="forum-eyebrow">HESAP</span><h1>Disiplin kayıtlarım</h1>'
            . '<p>Uyarı, kısıtlama ve diğer hesap işlemlerinin geçmişini görüntüle.</p></div>'
            . '<div class="discipline-points"><strong>' . $activePoints . '</strong><span>aktif puan</span></div></header>'
            . '<section class="surface-panel discipline-account-panel"><div class="discipline-account-list">'
            . $rows . '</div></section></section>';

        return ProfileHtml::page('Disiplin kayıtlarım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
