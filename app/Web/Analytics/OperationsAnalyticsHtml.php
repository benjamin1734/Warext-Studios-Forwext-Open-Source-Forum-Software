<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Analytics\Operations\OperationsAnalyticsSnapshot;
use Forwext\Core\Routing\BasePath;

final class OperationsAnalyticsHtml
{
    public static function page(OperationsAnalyticsSnapshot $snapshot, BasePath $basePath): string
    {
        $base = self::e($basePath->prepend('/admin/analytics/operations'));
        $overview = self::e($basePath->prepend('/admin/analytics'));
        $content = self::e($basePath->prepend('/admin/analytics/content'));

        $body = '<section class="card"><h1 style="margin-top:0">Moderasyon, Destek ve Hata Analizleri</h1>'
            . '<p class="muted">Operasyon hacmi, çözüm süreleri, SLA, disiplin, hata kategorileri ve personel iş yükü. '
            . 'Zaman aralıkları UTC tabanlıdır.</p>'
            . '<div class="market-actions"><a href="'.$overview.'">Forum genel dashboardu</a>'
            . '<a href="'.$content.'">İçerik & engagement</a>';
        foreach ([7, 30, 90] as $days) {
            $style = $snapshot->windowDays === $days ? ' style="font-weight:700"' : '';
            $body .= '<a'.$style.' href="'.$base.'?days='.$days.'">'.$days.' gün</a>';
        }
        $body .= '</div></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Moderasyon</h2><div class="stats-grid">'
            . self::stat('Rapor', $snapshot->reportVolume, 'Seçili dönemde tekil report gönderimi')
            . self::stat('Yeni report grubu', $snapshot->reportGroupsOpened, 'Yeni moderation case grupları')
            . self::stat('Çözülen grup', $snapshot->reportResolved, 'Dönemde terminal resolved güncellemesi')
            . self::stat('Reddedilen grup', $snapshot->reportRejected, 'Dönemde terminal rejected güncellemesi')
            . self::stat('Ort. sonuçlanma', self::duration($snapshot->reportAvgResolutionSeconds), 'Grup açılışından terminal güncellemeye')
            . self::stat('Uyarı', $snapshot->warningCount, 'Başlatılan warning eylemleri')
            . self::stat('Kısıtlama', $snapshot->restrictionCount, 'Başlatılan restriction eylemleri')
            . self::stat('Askıya alma', $snapshot->suspensionCount, 'Başlatılan suspension eylemleri')
            . self::stat('Ban', $snapshot->banCount, 'Başlatılan ban eylemleri')
            . '</div></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Destek</h2><div class="stats-grid">'
            . self::stat('Yeni ticket', $snapshot->supportCreated, 'Dönemde oluşturulan')
            . self::stat('Çözülen', $snapshot->supportResolved, 'resolved_at dönemde')
            . self::stat('Kapatılan', $snapshot->supportClosed, 'closed_at dönemde')
            . self::stat('İlk yanıt SLA ihlali', $snapshot->supportFirstResponseSlaBreaches, 'Dönem cohort’u')
            . self::stat('Çözüm SLA ihlali', $snapshot->supportResolutionSlaBreaches, 'Dönem cohort’u')
            . self::stat('Ort. ilk yanıt', self::duration($snapshot->supportAvgFirstResponseSeconds), 'Dönemde açılan ticket cohort’u')
            . self::stat('Ort. çözüm', self::duration($snapshot->supportAvgResolutionSeconds), 'Dönemde çözülen ticketlar')
            . '</div></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Hata bildirimleri</h2><div class="stats-grid">'
            . self::stat('Yeni hata', $snapshot->bugCreated, 'Dönemde oluşturulan')
            . self::stat('Çözülen', $snapshot->bugResolved, 'Dönemde finalize edilen resolved')
            . self::stat('Reddedilen', $snapshot->bugRejected, 'Dönemde finalize edilen rejected')
            . self::stat('Duplicate', $snapshot->bugDuplicate, 'Dönemde finalize edilen duplicate')
            . self::stat('Ort. finalizasyon', self::duration($snapshot->bugAvgFinalizationSeconds), 'Oluşturma → final durum')
            . '</div></section>';

        $body .= self::bugCategoryTable($snapshot);
        $body .= self::staffTable($snapshot);

        $body .= '<section class="card" style="margin-top:16px"><h2>Veri sınırları</h2>'
            . '<p class="muted">Bu ekran serbest metin ticket/report/bug içeriğini veya audit snapshotlarını okumaz; '
            . 'yalnız durum, zaman, kategori, assignment ve eylem metadatasını toplulaştırır. Personel tablosundaki aktif assignment '
            . 'kolonları anlık backlog, action kolonları ise seçili dönem hacmidir. Toplam yalnız iş yükünü sıralamak için bu görünür '
            . 'ham sayaçların toplamıdır; performans/kalite puanı değildir.</p>'
            . '<p class="muted">Report çözüm süresi, report group şemasında ayrı resolved_at alanı bulunmadığı için terminal durumun '
            . 'updated_at zamanını kullanır. Destek ve bug süreleri kendi authoritative response/resolved/finalized zaman alanlarından hesaplanır.</p>'
            . '<p class="muted">Üretildi: '.self::e($snapshot->generatedAt->format('Y-m-d H:i:s')).' UTC</p></section>';

        return ProfileHtml::page('Moderasyon, Destek ve Hata Analizleri', $body, $basePath, authenticated: true);
    }

    private static function bugCategoryTable(OperationsAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Bug kategorileri</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Kategori</th><th>Toplam</th><th>Aktif</th><th>Çözüldü</th><th>Reddedildi</th><th>Duplicate</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->bugCategories === []) {
            $body .= '<tr><td colspan="6" class="muted">Kategori verisi yok.</td></tr>';
        }
        foreach ($snapshot->bugCategories as $row) {
            $body .= '<tr><td>'.self::e($row['label']).'</td><td>'.self::n($row['total']).'</td>'
                . '<td>'.self::n($row['active']).'</td><td>'.self::n($row['resolved']).'</td>'
                . '<td>'.self::n($row['rejected']).'</td><td>'.self::n($row['duplicate']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function staffTable(OperationsAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Personel iş yükü</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Personel</th><th>Aktif report</th><th>Aktif destek</th><th>Aktif bug</th>'
            . '<th>Disiplin eylemi</th><th>Audit action</th><th>Toplam</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->staffWorkload === []) {
            $body .= '<tr><td colspan="7" class="muted">Personel iş yükü verisi yok.</td></tr>';
        }
        foreach ($snapshot->staffWorkload as $row) {
            $body .= '<tr><td>'.self::e($row['username']).'</td>'
                . '<td>'.self::n($row['active_reports']).'</td><td>'.self::n($row['active_support']).'</td>'
                . '<td>'.self::n($row['active_bugs']).'</td><td>'.self::n($row['discipline_actions']).'</td>'
                . '<td>'.self::n($row['audit_actions']).'</td><td>'.self::n($row['total']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function stat(string $label, int|string $value, string $hint): string
    {
        $display = is_int($value) ? self::n($value) : $value;
        return '<div class="card stat"><span class="muted">'.self::e($label).'</span>'
            . '<strong>'.self::e($display).'</strong><small class="muted">'.self::e($hint).'</small></div>';
    }

    private static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = max(0, (int) round($seconds));
        if ($seconds < 60) {
            return $seconds.' sn';
        }
        if ($seconds < 3600) {
            return number_format($seconds / 60, 1, ',', '.').' dk';
        }
        if ($seconds < 86400) {
            return number_format($seconds / 3600, 1, ',', '.').' sa';
        }
        return number_format($seconds / 86400, 1, ',', '.').' gün';
    }

    private static function n(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
