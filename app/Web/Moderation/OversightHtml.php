<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Oversight\OversightAnomalyFlag;
use Forwext\Core\Moderation\Oversight\OversightEntry;
use Forwext\Core\Moderation\Oversight\OversightOverview;
use Forwext\Core\Moderation\Oversight\OversightReviewCase;
use Forwext\Core\Routing\BasePath;

final class OversightHtml
{
    public static function page(
        OversightOverview $overview,
        BasePath $basePath,
        OversightCapabilities $capabilities,
    ): string {
        $verification = $overview->verification === null
            ? '<p><a href="' . self::e($basePath->prepend('/moderation/oversight?verify=1')) . '">Tüm hash zincirini doğrula</a></p>'
            : '<p><strong>Zincir: ' . ($overview->verification->valid ? 'GEÇERLİ' : 'BOZUK')
                . '</strong> · kontrol edilen: ' . $overview->verification->checkedEntries
                . ' · son sıra: ' . $overview->verification->lastSequence
                . ($overview->verification->error === null ? '' : ' · hata: ' . self::e($overview->verification->error))
                . '</p>';

        $entries = '';
        foreach ($overview->entries as $entry) {
            $entries .= self::entry($entry, $basePath, $capabilities->canReview);
        }
        if ($entries === '') $entries = '<div class="empty">Bağımsız denetim kaydı yok.</div>';

        $cases = '';
        foreach ($overview->cases as $case) {
            $cases .= self::caseRow($case, $basePath, $capabilities->canReview);
        }
        if ($cases === '') $cases = '<div class="empty">Açık review case yok.</div>';

        $flags = '';
        foreach ($overview->flags as $flag) {
            $flags .= self::flagRow($flag, $basePath, $capabilities->canReview);
        }
        if ($flags === '') $flags = '<div class="empty">Açık anomaly flag yok.</div>';

        $script = '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';
        $content = '<div class="card"><h1 style="margin:0">Bağımsız Moderasyon Denetimi</h1>'
            . '<p class="muted">Bu kayıtlar normal operational audit streaminden ayrıdır. Zincir kayıtları append-only ve SHA-256 hash zincirlidir; review/flag metadata ayrı tutulur.</p>'
            . $verification . '</div>'
            . '<section class="card section"><h2>Son zincir kayıtları</h2>' . $entries . '</section>'
            . '<section class="card section"><h2>Açık review cases</h2>' . $cases . '</section>'
            . '<section class="card section"><h2>Anomaly flags</h2>' . $flags . '</section>'
            . $script;

        return ProfileHtml::page('Bağımsız Moderasyon Denetimi', $content, $basePath, authenticated: true);
    }

    private static function entry(OversightEntry $entry, BasePath $basePath, bool $canReview): string
    {
        $controls = '';
        if ($canReview) {
            $controls = '<details><summary>Review / flag</summary>'
                . '<form method="post" action="' . self::e($basePath->prepend('/moderation/oversight/cases'))
                . '" data-moderation-form class="presence-settings">'
                . '<input type="hidden" name="source_audit_id" value="' . self::e($entry->sourceAuditId->value()) . '">'
                . '<label><span class="muted">Review özeti</span><input name="summary" maxlength="1000" required></label>'
                . '<button type="submit">Case aç</button></form>'
                . '<form method="post" action="' . self::e($basePath->prepend('/moderation/oversight/flags'))
                . '" data-moderation-form class="presence-settings">'
                . '<input type="hidden" name="source_audit_id" value="' . self::e($entry->sourceAuditId->value()) . '">'
                . '<label><span class="muted">Flag tipi</span><input name="flag_type" value="manual.review" maxlength="64" required></label>'
                . '<label><span class="muted">Severity</span><select name="severity">'
                . '<option value="low">Low</option><option value="medium">Medium</option>'
                . '<option value="high">High</option><option value="critical">Critical</option></select></label>'
                . '<label><span class="muted">Detay</span><input name="details" maxlength="1000" required></label>'
                . '<button type="submit">Flag ekle</button></form></details>';
        }

        return '<article class="search-hit"><span class="search-hit-type">#' . $entry->sequence . '</span>'
            . '<h3>' . self::e($entry->action) . '</h3>'
            . '<div class="muted">Actor: ' . self::e($entry->actorUserId->value())
            . ' · Target: ' . self::e($entry->targetType . ':' . $entry->targetId) . '</div>'
            . '<div class="muted">Request: ' . self::e($entry->requestId)
            . ' · Hash: ' . self::e(substr($entry->chainHash, 0, 20)) . '…</div>'
            . $controls . '</article>';
    }

    private static function caseRow(OversightReviewCase $case, BasePath $basePath, bool $canReview): string
    {
        $form = $canReview
            ? '<form method="post" action="'
                . self::e($basePath->prepend('/moderation/oversight/cases/' . $case->caseId->value() . '/resolve'))
                . '" data-moderation-form class="presence-settings">'
                . '<label><span class="muted">Çözüm</span><input name="resolution" maxlength="1000" required></label>'
                . '<button type="submit">Case kapat</button></form>'
            : '';

        return '<article class="search-hit"><h3>' . self::e($case->summary) . '</h3>'
            . '<div class="muted">Audit: ' . self::e($case->sourceAuditId->value())
            . ' · Açan: ' . self::e($case->openedByUserId->value()) . '</div>' . $form . '</article>';
    }

    private static function flagRow(OversightAnomalyFlag $flag, BasePath $basePath, bool $canReview): string
    {
        $form = $canReview
            ? '<form method="post" action="'
                . self::e($basePath->prepend('/moderation/oversight/flags/' . $flag->flagId->value() . '/resolve'))
                . '" data-moderation-form class="presence-settings">'
                . '<label><span class="muted">Çözüm</span><input name="resolution" maxlength="1000" required></label>'
                . '<button type="submit">Flag kapat</button></form>'
            : '';

        return '<article class="search-hit"><span class="search-hit-type">' . self::e($flag->severity->value) . '</span>'
            . '<h3>' . self::e($flag->flagType) . '</h3><p>' . self::e($flag->details) . '</p>'
            . '<div class="muted">Audit: ' . self::e($flag->sourceAuditId->value()) . '</div>' . $form . '</article>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
