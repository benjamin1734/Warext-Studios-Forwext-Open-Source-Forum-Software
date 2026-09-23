<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Analytics\Report\AnalyticsReportDataset;
use Forwext\Core\Analytics\Report\AnalyticsReportDefinition;
use Forwext\Core\Analytics\Report\AnalyticsReportResult;
use Forwext\Core\Analytics\Report\AnalyticsSavedReport;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;

final class AnalyticsReportHtml
{
    /**
     * @param list<AnalyticsReportDataset> $datasets
     * @param list<AnalyticsSavedReport> $savedReports
     */
    public static function page(
        AnalyticsReportDefinition $definition,
        AnalyticsReportResult $result,
        array $datasets,
        array $savedReports,
        ?AnalyticsSavedReport $selected,
        EntityId $actor,
        string $csrf,
        bool $canExport,
        bool $canUseUnaggregated,
        bool $canManageAll,
        bool $saved,
        bool $deleted,
        BasePath $basePath,
    ): string {
        $base = $basePath->prepend('/admin/analytics/reports');
        $commerce = self::e($basePath->prepend('/admin/analytics/commerce'));
        $overview = self::e($basePath->prepend('/admin/analytics'));
        $body = '<section class="card"><h1 style="margin-top:0">Analytics Report Builder</h1>'
            . '<p class="muted">Tarih aralığı, güvenli dataset filtreleri, kaydedilmiş raporlar, privacy aggregation ve CSV/JSON export.</p>'
            . '<div class="market-actions"><a href="'.$overview.'">Analytics dashboard</a>'
            . '<a href="'.$commerce.'">Marketplace & gelir</a></div></section>';

        if ($saved) {
            $body .= '<section class="card" style="margin-top:16px"><strong>Rapor kaydedildi.</strong></section>';
        }
        if ($deleted) {
            $body .= '<section class="card" style="margin-top:16px"><strong>Rapor silindi.</strong></section>';
        }

        $body .= '<section class="card" style="margin-top:16px"><h2>Rapor oluştur</h2>'
            . '<form method="get" action="'.self::e($base).'">'
            . '<div class="form-grid">'
            . '<label>Dataset<select name="dataset">';
        foreach ($datasets as $dataset) {
            $selectedOption = $dataset === $definition->dataset ? ' selected' : '';
            $body .= '<option value="'.self::e($dataset->value).'"'.$selectedOption.'>'.self::e($dataset->label()).'</option>';
        }
        $body .= '</select></label>'
            . self::dateField('Başlangıç', 'from', $definition->from->format('Y-m-d'))
            . self::dateField('Bitiş', 'to', $definition->to->format('Y-m-d'))
            . '<label>Privacy min count<input type="number" name="privacy_min_count" min="1" max="100" value="'
            . self::e((string)$definition->privacyMinCount).'"></label>'
            . '</div>'
            . self::filterFields($definition)
            . '<div class="market-actions" style="margin-top:12px"><button type="submit">Raporu çalıştır</button></div>'
            . '</form>'
            . '<p class="muted">Aralık en fazla 366 gündür. '
            . ($canUseUnaggregated
                ? 'Bu hesabın düşük aggregation threshold kullanma izni vardır.'
                : 'Bu hesap için etkin privacy threshold en az 5 olarak zorlanır.')
            . '</p></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Sonuç</h2>'
            . '<p class="muted">Etkin privacy min count: '.self::e((string)$result->effectivePrivacyMinCount)
            . ' · Gizlenen düşük hacimli satır: '.self::e((string)$result->suppressedRows).'</p>';

        if ($canExport) {
            $query = self::definitionQuery($definition);
            $csv = self::e($basePath->prepend('/admin/analytics/reports/export').'?'.http_build_query(
                ['format'=>'csv'] + $query,
                '',
                '&',
                PHP_QUERY_RFC3986,
            ));
            $json = self::e($basePath->prepend('/admin/analytics/reports/export').'?'.http_build_query(
                ['format'=>'json'] + $query,
                '',
                '&',
                PHP_QUERY_RFC3986,
            ));
            $body .= '<div class="market-actions"><a href="'.$csv.'">CSV indir</a><a href="'.$json.'">JSON indir</a></div>';
        }

        $body .= self::resultTable($result).'</section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Raporu kaydet</h2>'
            . '<form method="post" action="'.self::e($base).'">'
            . '<input type="hidden" name="_csrf" value="'.self::e($csrf).'">'
            . '<input type="hidden" name="action" value="save">';
        if ($selected !== null) {
            $body .= '<input type="hidden" name="report_id" value="'.self::e($selected->reportId->value()).'">';
        }
        $body .= self::definitionHiddenFields($definition)
            . '<label>Rapor adı<input type="text" name="name" maxlength="120" required value="'
            . self::e($selected?->name ?? '').'"></label>'
            . '<div class="market-actions" style="margin-top:12px"><button type="submit">'
            . ($selected === null ? 'Yeni kaydet' : 'Kaydı güncelle')
            . '</button></div></form>';

        if ($selected !== null) {
            $body .= '<form method="post" action="'.self::e($base).'" style="margin-top:10px">'
                . '<input type="hidden" name="_csrf" value="'.self::e($csrf).'">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="report_id" value="'.self::e($selected->reportId->value()).'">'
                . '<button type="submit">Bu kaydı sil</button></form>';
        }
        $body .= '</section>';

        $body .= '<section class="card" style="margin-top:16px;overflow:auto"><h2>Kaydedilmiş raporlar</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Ad</th><th>Dataset</th><th>Aralık</th><th>Sahiplik</th><th></th>'
            . '</tr></thead><tbody>';
        if ($savedReports === []) {
            $body .= '<tr><td colspan="5" class="muted">Kaydedilmiş rapor yok.</td></tr>';
        }
        foreach ($savedReports as $report) {
            $owner = $report->ownerUserId->value() === $actor->value() ? 'Sizin' : ($canManageAll ? 'Diğer yetkili' : '—');
            $url = self::e($base.'?id='.rawurlencode($report->reportId->value()));
            $body .= '<tr><td>'.self::e($report->name).'</td>'
                . '<td>'.self::e($report->definition->dataset->label()).'</td>'
                . '<td>'.self::e($report->definition->from->format('Y-m-d').' → '.$report->definition->to->format('Y-m-d')).'</td>'
                . '<td>'.self::e($owner).'</td><td><a href="'.$url.'">Aç</a></td></tr>';
        }
        $body .= '</tbody></table></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Privacy ve erişim</h2>'
            . '<p class="muted">Rapor builder yalnız aggregate satırlar üretir; raw kullanıcı kimliği, IP, cihaz fingerprinti, '
            . 'ticket/bug/report serbest metni veya payment billing/receipt payloadları bu datasetlerde seçilmez. '
            . 'Dataset erişimi backend permission ile, export ayrıca analytics.export ile doğrulanır.</p></section>';

        return ProfileHtml::page('Analytics Report Builder', $body, $basePath, authenticated:true);
    }

    private static function filterFields(AnalyticsReportDefinition $definition): string
    {
        $labels = [
            'event_key'=>'Event key',
            'content_type'=>'Content type',
            'scope'=>'Audit scope',
            'action'=>'Audit action',
            'currency'=>'Currency',
            'order_state'=>'Order state',
            'payment_state'=>'Payment state',
            'campaign'=>'Campaign key',
            'state'=>'State',
        ];
        $html = '<div class="form-grid" style="margin-top:12px">';
        foreach ($definition->dataset->allowedFilters() as $key) {
            $html .= '<label>'.self::e($labels[$key] ?? $key)
                . '<input type="text" name="filter_'.self::e($key).'" maxlength="96" value="'
                . self::e($definition->filters[$key] ?? '').'"></label>';
        }
        return $html.'</div>';
    }

    private static function resultTable(AnalyticsReportResult $result): string
    {
        $html = '<div style="overflow:auto;margin-top:12px"><table style="width:100%;border-collapse:collapse"><thead><tr>';
        foreach ($result->columns as $column) {
            $html .= '<th>'.self::e($column).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        if ($result->rows === []) {
            $html .= '<tr><td colspan="'.count($result->columns).'" class="muted">Görünür aggregate satır yok.</td></tr>';
        }
        foreach ($result->rows as $row) {
            $html .= '<tr>';
            foreach ($result->columns as $column) {
                $value = $row[$column] ?? null;
                $html .= '<td>'.self::e(self::display($value)).'</td>';
            }
            $html .= '</tr>';
        }
        return $html.'</tbody></table></div>';
    }

    private static function dateField(string $label, string $name, string $value): string
    {
        return '<label>'.self::e($label).'<input type="date" name="'.self::e($name).'" value="'.self::e($value).'" required></label>';
    }

    private static function definitionHiddenFields(AnalyticsReportDefinition $definition): string
    {
        $html = '<input type="hidden" name="dataset" value="'.self::e($definition->dataset->value).'">'
            . '<input type="hidden" name="from" value="'.self::e($definition->from->format('Y-m-d')).'">'
            . '<input type="hidden" name="to" value="'.self::e($definition->to->format('Y-m-d')).'">'
            . '<input type="hidden" name="privacy_min_count" value="'.self::e((string)$definition->privacyMinCount).'">';
        foreach ($definition->filters as $key=>$value) {
            $html .= '<input type="hidden" name="filter_'.self::e($key).'" value="'.self::e($value).'">';
        }
        return $html;
    }

    /** @return array<string,string|int> */
    private static function definitionQuery(AnalyticsReportDefinition $definition): array
    {
        $query = [
            'dataset'=>$definition->dataset->value,
            'from'=>$definition->from->format('Y-m-d'),
            'to'=>$definition->to->format('Y-m-d'),
            'privacy_min_count'=>$definition->privacyMinCount,
        ];
        foreach ($definition->filters as $key=>$value) {
            $query['filter_'.$key] = $value;
        }
        return $query;
    }

    private static function display(int|float|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_float($value)) {
            return number_format($value, 2, ',', '.');
        }
        if (is_int($value)) {
            return number_format($value, 0, ',', '.');
        }
        return $value;
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
