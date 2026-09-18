<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Discipline\DisciplineAction;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineOverview;
use Forwext\Core\Moderation\Discipline\DisciplineRestrictionKey;
use Forwext\Core\Moderation\Discipline\WarningDefinition;
use Forwext\Core\Routing\BasePath;

final class DisciplineHtml
{
    /** @param array<string,string> $usernames */
    public static function page(
        DisciplineOverview $overview,
        array $usernames,
        BasePath $basePath,
        DisciplineCapabilities $capabilities,
    ): string {
        $forms = self::actionForms($overview, $basePath, $capabilities);
        $definitions = self::definitions($overview, $basePath, $capabilities);
        $actions = self::actions($overview, $usernames, $basePath, $capabilities);
        $script = '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';

        $content = '<div class="card"><h1 style="margin:0">Uyarı ve disiplin yönetimi</h1>'
            . '<p class="muted">Uyarı puanları, süreli/kalıcı kısıtlamalar, askıya alma ve ban işlemleri '
            . 'ortak permission ve audit altyapısına bağlıdır.</p></div>'
            . $forms . $definitions
            . '<section class="card section"><h2>Disiplin geçmişi</h2>' . $actions . '</section>'
            . $script;

        return ProfileHtml::page('Disiplin', $content, $basePath, authenticated: true);
    }

    private static function actionForms(
        DisciplineOverview $overview,
        BasePath $basePath,
        DisciplineCapabilities $capabilities,
    ): string {
        $action = self::e($basePath->prepend('/moderation/discipline/actions'));
        $forms = '';

        if ($capabilities->canIssueWarning) {
            $options = '';
            foreach ($overview->warningDefinitions as $definition) {
                if (!$definition->active) continue;
                $options .= '<option value="' . self::e($definition->key) . '">'
                    . self::e($definition->label) . ' (' . $definition->points . ' puan)</option>';
            }
            $forms .= '<details class="card section"><summary><strong>Uyarı ver</strong></summary>'
                . '<form method="post" action="' . $action . '" data-moderation-form class="search-form">'
                . '<input type="hidden" name="action_type" value="warning">'
                . self::usernameAndReasonFields()
                . '<label><span>Uyarı tanımı</span><select name="warning_definition" required>' . $options . '</select></label>'
                . '<div class="search-actions"><button type="submit">Uyarıyı uygula</button></div></form></details>';
        }

        if ($capabilities->canRestrict) {
            $forms .= '<details class="card section"><summary><strong>Posting / içerik kısıtlaması</strong></summary>'
                . '<form method="post" action="' . $action . '" data-moderation-form class="search-form">'
                . '<input type="hidden" name="action_type" value="restriction">'
                . self::usernameAndReasonFields()
                . '<label><span>Kısıtlama</span><select name="restriction_key">'
                . '<option value="posting">Gönderi oluşturma</option><option value="content">İçerik oluşturma</option>'
                . '</select></label>'
                . '<label><span>Süre (saat)</span><input name="duration_hours" inputmode="numeric" '
                . 'placeholder="Boş = kalıcı" maxlength="5"></label>'
                . '<div class="search-actions"><button type="submit">Kısıtlamayı uygula</button></div></form></details>';
        }

        if ($capabilities->canBan) {
            $forms .= '<details class="card section"><summary><strong>Askıya al / banla</strong></summary>'
                . '<form method="post" action="' . $action . '" data-moderation-form class="search-form">'
                . self::usernameAndReasonFields()
                . '<label><span>İşlem</span><select name="action_type">'
                . '<option value="suspension">Geçici askıya alma</option><option value="ban">Ban</option></select></label>'
                . '<label><span>Süre (saat)</span><input name="duration_hours" inputmode="numeric" maxlength="5" '
                . 'placeholder="Ban için boş = kalıcı"></label>'
                . '<div class="search-actions"><button type="submit">İşlemi uygula</button></div></form></details>';
        }

        return $forms;
    }

    private static function definitions(
        DisciplineOverview $overview,
        BasePath $basePath,
        DisciplineCapabilities $capabilities,
    ): string {
        $rows = '';
        foreach ($overview->warningDefinitions as $definition) {
            $rows .= '<article class="search-hit"><h3>' . self::e($definition->label) . '</h3>'
                . '<div class="muted">' . self::e($definition->key) . ' · ' . $definition->points . ' puan · '
                . ($definition->expiryDays === null ? 'süresiz puan' : $definition->expiryDays . ' gün')
                . ' · ' . ($definition->active ? 'aktif' : 'pasif') . '</div>'
                . ($definition->description === '' ? '' : '<p>' . self::e($definition->description) . '</p>')
                . '</article>';
        }
        if ($rows === '') $rows = '<div class="empty">Uyarı tanımı yok.</div>';

        $editor = '';
        if ($capabilities->canManageWarningDefinitions) {
            $editor = '<details><summary><strong>Gelişmiş: uyarı tanımı oluştur/güncelle</strong></summary>'
                . '<form method="post" action="' . self::e($basePath->prepend('/moderation/discipline/warning-definitions'))
                . '" data-moderation-form class="search-form">'
                . '<label><span>Anahtar</span><input name="definition_key" maxlength="64" required></label>'
                . '<label><span>Başlık</span><input name="label" maxlength="100" required></label>'
                . '<label class="search-wide"><span>Açıklama</span><input name="description" maxlength="500"></label>'
                . '<label><span>Puan</span><input name="points" value="1" inputmode="numeric" required></label>'
                . '<label><span>Puan süresi (gün)</span><input name="expiry_days" inputmode="numeric" placeholder="Boş = süresiz"></label>'
                . '<label><span>Durum</span><select name="active"><option value="1">Aktif</option><option value="0">Pasif</option></select></label>'
                . '<label><span>Sıra</span><input name="sort_order" value="100" inputmode="numeric" required></label>'
                . '<div class="search-actions"><button type="submit">Tanımı kaydet</button></div>'
                . '</form></details>';
        }

        return '<section class="card section"><h2>Uyarı tanımları</h2>' . $rows . $editor . '</section>';
    }

    /** @param array<string,string> $usernames */
    private static function actions(
        DisciplineOverview $overview,
        array $usernames,
        BasePath $basePath,
        DisciplineCapabilities $capabilities,
    ): string {
        if ($overview->actions === []) return '<div class="empty">Disiplin kaydı yok.</div>';
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = '';

        foreach ($overview->actions as $action) {
            $username = $usernames[$action->userId->value()] ?? $action->userId->value();
            $details = 'Neden: ' . self::e($action->reasonCode->value());
            if ($action->type === DisciplineActionType::Warning) {
                $details .= ' · ' . $action->points . ' puan';
            }
            if ($action->type === DisciplineActionType::Restriction) {
                $details .= ' · ' . self::e(implode(', ', array_map(
                    static fn (DisciplineRestrictionKey $key): string => $key->label(),
                    $action->restrictions,
                )));
            }
            $details .= ' · Başlangıç: ' . self::e($action->startsAt->format('Y-m-d H:i')) . ' UTC';
            $details .= $action->expiresAt === null
                ? (in_array($action->type, [DisciplineActionType::Ban, DisciplineActionType::Restriction], true) ? ' · Kalıcı' : '')
                : ' · Bitiş: ' . self::e($action->expiresAt->format('Y-m-d H:i')) . ' UTC';

            $appeal = $action->appealReference();
            $revoke = '';
            if ($capabilities->canRevoke && $action->isActiveAt($now)) {
                $revoke = '<form method="post" action="'
                    . self::e($basePath->prepend('/moderation/discipline/' . rawurlencode($action->actionId->value()) . '/revoke'))
                    . '" data-moderation-form class="presence-settings">'
                    . '<label><span class="muted">Kaldırma nedeni</span><input name="reason" maxlength="1000" required></label>'
                    . '<button type="submit">Kaldır</button></form>';
            }

            $rows .= '<article class="search-hit"><span class="search-hit-type">'
                . self::e($action->type->label()) . '</span><h3>' . self::e($username) . '</h3>'
                . '<div class="muted">Durum: ' . self::e($action->statusAt($now)) . ' · ' . $details . '</div>'
                . '<p>' . self::e($action->reasonText) . '</p>'
                . ($appeal === null ? '' : '<div class="muted">İtiraz referansı: ' . self::e($appeal) . '</div>')
                . $revoke . '</article>';
        }
        return $rows;
    }

    private static function usernameAndReasonFields(): string
    {
        return '<label><span>Kullanıcı adı</span><input name="username" maxlength="128" required></label>'
            . '<label><span>Neden kodu</span><input name="reason_code" maxlength="64" value="discipline.manual" required></label>'
            . '<label class="search-wide"><span>Açıklama</span><input name="reason_text" maxlength="2000" required></label>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
